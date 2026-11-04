<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Retry;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\LoggerInterface;
use Axitrace\PrestaShop\Adapter\ShopGroupResolverInterface;
use Axitrace\PrestaShop\Config\FailureRecorder;
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
 * key (without one the attempt fails), a rejected key parks the row (it waits in the delivery log
 * for "Retry now" after the merchant fixed the key), and a delivered refund marks its
 * `axitrace_refund` row sent.
 *
 * Also prunes dead rows and cart snapshots older than 30 days, and refreshes AXITRACE_RETRY_DUE_AT,
 * even when a row or the batch failed. Never throws for a row: a row whose handling threw is
 * counted as failed, logged and recorded ({@see FAILURE_CODE}), and the batch goes on.
 */
final class RetryRunner
{
    public const LAZY_BATCH = 20;
    public const LAZY_BUDGET_SECONDS = 1.5;
    public const CRON_BATCH = 200;
    public const CRON_BUDGET_SECONDS = 25.0;

    public const FAILURE_CODE = 'retry_row_failed';

    public function __construct(
        private readonly FailedEventQueue $queue,
        private readonly PurchaseSender $purchases,
        private readonly RefundSender $refunds,
        private readonly PurchaseLedger $ledger,
        private readonly RefundLedger $refundLedger,
        private readonly CartSnapshotRepository $cartSnapshots,
        private readonly ShopGroupResolverInterface $shopGroups,
        private readonly FailureRecorder $failures,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function run(int $batch, float $budgetSeconds): RetryReport
    {
        $start = $this->clock->now();
        $processed = $delivered = $failed = $dead = $parked = 0;
        $exhausted = false;

        try {
            foreach ($this->queue->due($start, $batch) as $event) {
                if (self::elapsed($start, $this->clock->now()) >= $budgetSeconds) {
                    $exhausted = true;
                    break;
                }
                ++$processed;

                try {
                    $outcome = $this->retry($event);
                } catch (\Throwable $e) {
                    $this->rowFailed($event, $e);
                    $outcome = 'failed';
                }

                switch ($outcome) {
                    case 'delivered':
                        ++$delivered;
                        break;
                    case 'dead':
                        ++$dead;
                        break;
                    case 'parked':
                        ++$parked;
                        break;
                    default:
                        ++$failed;
                }
            }
        } finally {
            $now = $this->clock->now();
            $this->queue->pruneDead($now);
            $this->cartSnapshots->prune($now);
            $this->queue->refreshDueAt();
        }

        return new RetryReport($processed, $delivered, $failed, $dead, $parked, $exhausted);
    }

    /**
     * @return 'delivered'|'failed'|'dead'|'parked'
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
            $result = $event->endpoint === FailedEventQueue::ENDPOINT_REFUND
                ? $this->refunds->send($payload, $this->scopeOf($event))
                : $this->purchases->send($payload, $this->scopeOf($event));
        } catch (SecretKeyRejectedException) {
            // Kept, with its axitrace_refund reservation: "Retry now" sends it once the key is fixed.
            $this->queue->markParked($event, 'AxiTrace rejected the secret key (HTTP 401): enter a valid secret key, then click Retry now.', $now);
            $this->logger->error(sprintf('the refund %s waits for a valid secret key: AxiTrace rejected the current one.', $event->eventId));

            return 'parked';
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

    private function scopeOf(FailedEvent $event): ShopScope
    {
        return ShopScope::shop($this->shopGroups->groupOf($event->idShop), $event->idShop);
    }

    /**
     * A row whose handling threw (database, ledger): counted as a failed attempt when the queue can
     * still be written (backoff, then dead after MAX_ATTEMPTS, so it never blocks the head of the
     * batch), logged and recorded.
     */
    private function rowFailed(FailedEvent $event, \Throwable $e): void
    {
        $what = $event->endpoint === FailedEventQueue::ENDPOINT_REFUND ? 'refund' : 'purchase';
        $message = sprintf('retrying the %s %s failed (%s: %s); it is tried again after the backoff.', $what, $event->eventId, $e::class, $e->getMessage());
        try {
            if ($this->queue->recordFailure($event, mb_substr(sprintf('%s: %s', $e::class, $e->getMessage()), 0, 300), $this->clock->now())) {
                $message .= sprintf(' It was given up after %d attempts.', FailedEventQueue::MAX_ATTEMPTS);
            }
        } catch (\Throwable $queueError) {
            $message .= sprintf(' Counting the attempt failed too (%s: %s), so it is retried at the next run.', $queueError::class, $queueError->getMessage());
        }
        try {
            // The queue is shared by every shop: shown in every shop context.
            $this->failures->recordGlobal(self::FAILURE_CODE, $message);
        } catch (\Throwable $recordError) {
            $this->logger->error(sprintf('%s; recording the failure failed too (%s: %s)', $message, $recordError::class, $recordError->getMessage()));
        }
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
