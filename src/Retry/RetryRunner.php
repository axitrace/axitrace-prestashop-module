<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Retry;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\LoggerInterface;
use Axitrace\PrestaShop\Adapter\ShopGroupResolverInterface;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Http\DeliveryOutcome;
use Axitrace\PrestaShop\Http\DeliveryResult;
use Axitrace\PrestaShop\Http\SecretKeyRejectedException;
use Axitrace\PrestaShop\Normalizer\OrderEventNormalizer;
use Axitrace\PrestaShop\Purchase\PurchaseLedger;
use Axitrace\PrestaShop\Purchase\PurchaseSender;
use Axitrace\PrestaShop\Refund\RefundLedger;
use Axitrace\PrestaShop\Refund\RefundSender;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;

/**
 * Resends due rows of the failed event queue, in a batch and within a time budget (lazy trigger:
 * 20 rows / 1.5 s; cron URL: 200 rows). Every row is sent with the configuration of ITS shop, read
 * now: endpoint, secret key (A27). A purchase goes through {@see PurchaseSender} (same rules as the
 * first attempt: costs only with a key, keyless resend when the key is rejected); a delivered
 * purchase marks its order SENT. A refund goes through {@see RefundSender}: it needs the secret
 * key (without one the attempt fails), a rejected key drops the row with a warning (it can never
 * be delivered with that key), and a delivered refund marks its `axitrace_refund` row sent.
 *
 * Also prunes dead rows and cart snapshots older than 30 days, and refreshes AXITRACE_RETRY_DUE_AT.
 * Never throws for a row: a row's failure is counted as a failed attempt.
 */
final class RetryRunner
{
    public const LAZY_BATCH = 20;
    public const LAZY_BUDGET_SECONDS = 1.5;
    public const CRON_BATCH = 200;
    public const CRON_BUDGET_SECONDS = 25.0;

    public function __construct(
        private readonly FailedEventQueue $queue,
        private readonly PurchaseSender $purchases,
        private readonly RefundSender $refunds,
        private readonly PurchaseLedger $ledger,
        private readonly RefundLedger $refundLedger,
        private readonly CartSnapshotRepository $cartSnapshots,
        private readonly ShopGroupResolverInterface $shopGroups,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function run(int $batch, float $budgetSeconds): RetryReport
    {
        $start = $this->clock->now();
        $processed = $delivered = $failed = $dead = $dropped = 0;
        $exhausted = false;

        foreach ($this->queue->due($start, $batch) as $event) {
            if (self::elapsed($start, $this->clock->now()) >= $budgetSeconds) {
                $exhausted = true;
                break;
            }
            ++$processed;

            switch ($this->retry($event)) {
                case 'delivered':
                    ++$delivered;
                    break;
                case 'dead':
                    ++$dead;
                    break;
                case 'dropped':
                    ++$dropped;
                    break;
                default:
                    ++$failed;
            }
        }

        $now = $this->clock->now();
        $this->queue->pruneDead($now);
        $this->cartSnapshots->prune($now);
        $this->queue->refreshDueAt();

        return new RetryReport($processed, $delivered, $failed, $dead, $dropped, $exhausted);
    }

    /**
     * @return 'delivered'|'failed'|'dead'|'dropped'
     */
    private function retry(FailedEvent $event): string
    {
        $now = $this->clock->now();
        $payload = $event->decodedPayload();
        if ($payload === null) {
            $this->queue->markDead($event, 'stored payload is not valid JSON', $now);

            return 'dead';
        }

        try {
            $scope = ShopScope::shop($this->shopGroups->groupOf($event->idShop), $event->idShop);
            $result = $event->endpoint === FailedEventQueue::ENDPOINT_REFUND
                ? $this->refunds->send($payload, $scope)
                : $this->purchases->send($payload, $scope);
        } catch (SecretKeyRejectedException) {
            $this->queue->delete($event->id);
            $this->releaseRefund($event, $payload);
            $this->logger->error(sprintf('the refund %s was dropped: AxiTrace rejected the secret key, so it can never be delivered with it.', $event->eventId));

            return 'dropped';
        } catch (\Throwable $e) {
            $result = new DeliveryResult(DeliveryOutcome::Retry, null, sprintf('%s: %s', $e::class, mb_substr($e->getMessage(), 0, 200)));
        }

        if ($result->isSent()) {
            $this->queue->delete($event->id);
            $this->markDelivered($event, $payload, $now);

            return 'delivered';
        }

        if ($result->outcome->isFinalFailure()) {
            $this->queue->markDead($event, $result->error, $now);
            $this->releaseRefund($event, $payload);

            return 'dead';
        }

        $isDead = $this->queue->recordFailure($event, $result->error, $now);
        if ($isDead) {
            $this->releaseRefund($event, $payload);
            $this->logger->error(sprintf('the %s %s was given up after %d attempts (%s).', $event->endpoint === FailedEventQueue::ENDPOINT_REFUND ? 'refund' : 'purchase', $event->eventId, FailedEventQueue::MAX_ATTEMPTS, $result->error));
        }

        return $isDead ? 'dead' : 'failed';
    }

    /**
     * A delivered purchase marks its order SENT; a delivered refund its `axitrace_refund` row.
     *
     * @param array<string, mixed> $payload
     */
    private function markDelivered(FailedEvent $event, array $payload, \DateTimeImmutable $now): void
    {
        if ($event->endpoint === FailedEventQueue::ENDPOINT_REFUND) {
            if (is_string($payload['refundId'] ?? null)) {
                $this->refundLedger->markSent($payload['refundId'], $now);
            }

            return;
        }

        $orderId = $payload['orderId'] ?? null;
        if (($payload['event'] ?? null) === OrderEventNormalizer::EVENT && is_string($orderId) && ctype_digit($orderId)) {
            $this->ledger->markSent((int) $orderId, $now);
        }
    }

    /**
     * A refund that will never be delivered no longer counts as reported (a later Canceled status
     * computes the rest without it).
     *
     * @param array<string, mixed> $payload
     */
    private function releaseRefund(FailedEvent $event, array $payload): void
    {
        if ($event->endpoint === FailedEventQueue::ENDPOINT_REFUND && is_string($payload['refundId'] ?? null)) {
            $this->refundLedger->release($payload['refundId']);
        }
    }

    private static function elapsed(\DateTimeImmutable $start, \DateTimeImmutable $now): float
    {
        return (float) $now->format('U.u') - (float) $start->format('U.u');
    }
}
