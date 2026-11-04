<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Hook\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\LoggerInterface;
use Axitrace\PrestaShop\Adapter\OrderReaderInterface;
use Axitrace\PrestaShop\Adapter\RefundReaderInterface;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\SecretKeyVault;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Http\DeliveryOutcome;
use Axitrace\PrestaShop\Http\DeliveryResult;
use Axitrace\PrestaShop\Http\SecretKeyRejectedException;
use Axitrace\PrestaShop\Normalizer\RefundEventNormalizer;
use Axitrace\PrestaShop\Purchase\OrderRowRepository;
use Axitrace\PrestaShop\Purchase\PurchaseLedger;
use Axitrace\PrestaShop\Purchase\PurchaseOrder;
use Axitrace\PrestaShop\Refund\RefundLedger;
use Axitrace\PrestaShop\Refund\RefundSender;
use Axitrace\PrestaShop\Refund\SentLine;
use Axitrace\PrestaShop\Refund\SentPurchase;
use Axitrace\PrestaShop\Retry\FailedEventQueue;

/**
 * Reports refunds and cancellations of orders whose purchase the module sent, for profit only
 * (A23): they never reach an ad platform and never change revenue or ROAS.
 *
 *  - `actionOrderSlipAdd` (partial refund, standard refund, return): one refund per credit slip.
 *    PrestaShop 9.x passes the slip (`orderSlipCreated`); 8.x passes none, so the newest slip of
 *    the order is read (T1 finding).
 *  - `actionProductCancel` with action 0 (a line cancelled on an unpaid order, in practice cash on
 *    delivery): one line refund, priced from what the purchase carried, because PrestaShop has
 *    already reduced or deleted the order line when the hook runs. Actions 1, 2 and 3 (standard
 *    refund, partial refund, return) are followed by a credit slip and are ignored here.
 *  - Refunded or Canceled status ({@see onStateChange()}, called by the status hook): one refund
 *    of the rest, only when the order has no credit slip and no earlier status refund.
 *
 * Sent only for an order whose purchase was claimed (sent, or queued for a retry), with tracking
 * enabled and a secret key for the ORDER's shop: the refund endpoint accepts nothing else. No key
 * is an expected setup, logged as info. `axitrace_refund` guarantees one send per refund id.
 *
 * Never throws: every failure is logged with class and message and recorded as the last failure.
 */
final class RefundHook
{
    public const STATE_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATE_REFUNDED = 'refunded';
    public const STATE_CANCELLED = 'cancelled';

    public const ACTION_CANCEL_LINE = 0;

    public const FAILURE_CODE = 'refund_failed';
    public const FAILURE_QUEUE_WRITE = 'refund_queue_write_failed';
    public const FAILURE_BASE_FALLBACK = 'refund_base_fallback';
    public const FAILURE_STATE_NOT_SAVED = 'refund_state_not_saved';

    private const OUTCOME_SEEN = 'seen';
    private const OUTCOME_DELIVERED = 'delivered';
    private const OUTCOME_PENDING = 'pending';
    private const OUTCOME_REFUSED = 'refused';

    /** Amounts below half a cent are "nothing left". */
    private const EPSILON = 0.005;

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly SecretKeyVault $vault,
        private readonly OrderRowRepository $rows,
        private readonly OrderReaderInterface $orders,
        private readonly RefundReaderInterface $refundSources,
        private readonly RefundEventNormalizer $normalizer,
        private readonly RefundLedger $ledger,
        private readonly RefundSender $sender,
        private readonly FailedEventQueue $queue,
        private readonly FailureRecorder $failures,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /** `actionOrderSlipAdd`; $idOrderSlip is null on PrestaShop 8.x. */
    public function onSlipAdded(int $idOrder, ?int $idOrderSlip): void
    {
        $this->guarded($idOrder, 'credit slip', function () use ($idOrder, $idOrderSlip): void {
            $context = $this->context($idOrder);
            if ($context === null) {
                return;
            }
            [, $scope, $sent] = $context;

            $slip = $this->refundSources->slip($idOrder, $idOrderSlip);
            if ($slip === null) {
                throw new \RuntimeException(sprintf('credit slip %s of order %d not found', $idOrderSlip === null ? '(newest)' : (string) $idOrderSlip, $idOrder));
            }

            $body = $this->normalizer->slip($slip, $this->currency($sent, $idOrder));
            $outcome = $this->dispatch($idOrder, $scope, RefundLedger::KIND_SLIP, $body);
            $this->afterDispatch($idOrder, $scope, $sent, null, (string) $body['refundId'], $outcome);
        });
    }

    /** `actionProductCancel`; only action 0 (line cancelled on an unpaid order) is reported. */
    public function onProductCancel(int $idOrder, int $idOrderDetail, int $cancelQuantity, int $action): void
    {
        if ($action !== self::ACTION_CANCEL_LINE || $idOrderDetail <= 0 || $cancelQuantity <= 0) {
            return;
        }

        $this->guarded($idOrder, 'line cancel', function () use ($idOrder, $idOrderDetail, $cancelQuantity): void {
            $context = $this->context($idOrder);
            if ($context === null) {
                return;
            }
            [, $scope, $sent] = $context;

            // PrestaShop reduced (or deleted) the line before this hook: what is left now.
            $current = $this->refundSources->currentLine($idOrderDetail);
            $remaining = $current->quantity ?? 0;

            $line = $sent?->line($idOrderDetail);
            if ($line !== null) {
                $cumulative = max($cancelQuantity, $line->quantity - $remaining);
            } elseif ($current !== null) {
                // Purchase claimed before 0.1.0: the live line still carries the unit price.
                $line = new SentLine($idOrderDetail, $current->productId, $current->variantId, $current->reference !== '' ? $current->reference : (string) $current->productId, $current->quantity + $cancelQuantity, $current->unitPriceTaxIncl);
                $cumulative = $cancelQuantity;
            } else {
                $this->logger->info(sprintf('the cancelled line %d of order %d was not reported: its price is no longer known.', $idOrderDetail, $idOrder));

                return;
            }

            $body = $this->normalizer->lineCancel($idOrder, $line, $cancelQuantity, $cumulative, $this->currency($sent, $idOrder), $this->clock->now());
            $outcome = $this->dispatch($idOrder, $scope, RefundLedger::KIND_LINE, $body);
            $this->afterDispatch($idOrder, $scope, $sent, null, (string) $body['refundId'], $outcome);
        });
    }

    /**
     * Refunded or Canceled status of a claimed order (`actionOrderStatusPostUpdate`): reports the
     * rest of the order, unless a credit slip exists (its refunds already carry the money) or a
     * status refund was reported before.
     *
     * @param array<string, mixed> $row the order's `axitrace_order` row
     */
    public function onStateChange(array $row, int $newStateId): void
    {
        $idOrder = (int) ($row['id_order'] ?? 0);

        $this->guarded($idOrder, 'status refund', function () use ($idOrder, $newStateId): void {
            $context = $this->context($idOrder);
            if ($context === null) {
                return;
            }
            [$row, $scope, $sent] = $context;

            $states = $this->orders->stateIds((int) $row['id_shop_group'], (int) $row['id_shop']);
            $isCancellation = $states->canceled > 0 && $newStateId === $states->canceled;
            if (!$isCancellation && !($states->refund > 0 && $newStateId === $states->refund)) {
                return;
            }

            if ($this->refundSources->slipCount($idOrder) > 0) {
                $this->logger->info(sprintf('order %d: status %d not reported as a refund, its credit slips carry the refunded amounts.', $idOrder, $newStateId));

                return;
            }
            if ($this->ledger->hasKind($idOrder, RefundLedger::KIND_STATE)) {
                return;
            }

            $order = $this->orders->read($idOrder);
            $rest = round($this->refundBase($idOrder, $scope, $sent, $order) - $this->ledger->totalFor($idOrder), 2);
            if ($rest < self::EPSILON) {
                $this->updateRefundState($idOrder, $scope, $sent, $isCancellation ? self::STATE_CANCELLED : self::STATE_REFUNDED);

                return;
            }

            $body = $this->normalizer->state($idOrder, $newStateId, $isCancellation, $rest, $this->currency($sent, $idOrder), $order->lines ?? [], $this->clock->now());
            $outcome = $this->dispatch($idOrder, $scope, RefundLedger::KIND_STATE, $body);
            $this->afterDispatch($idOrder, $scope, $sent, $isCancellation ? self::STATE_CANCELLED : self::STATE_REFUNDED, (string) $body['refundId'], $outcome);
        });
    }

    /**
     * The order's row, shop scope and sent purchase when a refund may be reported; null otherwise.
     *
     * @return array{0: array<string, mixed>, 1: ShopScope, 2: SentPurchase|null}|null
     */
    private function context(int $idOrder): ?array
    {
        $row = $idOrder > 0 ? $this->rows->find($idOrder) : null;
        if ($row === null || !PurchaseLedger::isClaimed($row)) {
            // Never sent (or before the module was installed): AxiTrace has nothing to reduce.
            return null;
        }

        $scope = ShopScope::shop((int) $row['id_shop_group'], (int) $row['id_shop']);
        if (!$this->config->isEnabled($scope)) {
            return null;
        }

        if (($this->vault->read($scope)->key ?? '') === '') {
            $this->logger->info(sprintf('refund of order %d not reported: no secret key is configured (refunds are sent with the secret key only).', $idOrder));

            return null;
        }

        return [$row, $scope, SentPurchase::decode(is_string($row['purchase_snapshot'] ?? null) ? $row['purchase_snapshot'] : null)];
    }

    /**
     * Records the refund once, sends it, and queues it when AxiTrace was not reached. When the
     * queue cannot be written the reservation is given back (a reserved refund that is neither
     * delivered nor queued would never be reported) and {@see FAILURE_QUEUE_WRITE} is recorded.
     *
     * @param array<string, mixed> $body
     *
     * @return self::OUTCOME_* what happened to the refund
     */
    private function dispatch(int $idOrder, ShopScope $scope, string $kind, array $body): string
    {
        $refundId = (string) $body['refundId'];
        $amount = is_float($body['amount'] ?? null) ? $body['amount'] : 0.0;
        if (!$this->ledger->reserve($idOrder, $refundId, $kind, $amount, (string) $body['currency'])) {
            return self::OUTCOME_SEEN; // reported before: the same slip, line cancel or status seen again
        }

        $idShop = $scope->idShop ?? 0;
        try {
            $result = $this->sender->send($body, $scope);
        } catch (SecretKeyRejectedException) {
            // Recorded for the banner by the sender. Parked with its reservation: the delivery log
            // lists it and "Retry now" sends it once the merchant entered a valid key.
            $queued = $this->queueWrite($idOrder, $refundId, $scope, 'AxiTrace rejected the secret key', function () use ($refundId, $idShop, $body): void {
                $this->queue->park($refundId, FailedEventQueue::ENDPOINT_REFUND, $idShop, $body, 'AxiTrace rejected the secret key (HTTP 401): enter a valid secret key, then click Retry now.', $this->clock->now());
            });

            return $queued ? self::OUTCOME_PENDING : self::OUTCOME_REFUSED;
        } catch (\Throwable $e) {
            $result = new DeliveryResult(DeliveryOutcome::Retry, null, sprintf('%s: %s', $e::class, mb_substr($e->getMessage(), 0, 200)));
        }

        if ($result->isSent()) {
            try {
                $this->ledger->markSent($refundId, $this->clock->now());
            } catch (\Throwable $e) {
                $this->failures->record(self::FAILURE_STATE_NOT_SAVED, sprintf('The refund %s of order %d WAS delivered to AxiTrace, but marking it sent in the shop failed (%s: %s). Do not report it again manually.', $refundId, $idOrder, $e::class, $e->getMessage()), $scope);
            }

            return self::OUTCOME_DELIVERED;
        }
        if ($result->outcome->isFinalFailure()) {
            $this->ledger->release($refundId);
            $this->queueWrite($idOrder, $refundId, $scope, $result->error, function () use ($refundId, $idShop, $body, $result): void {
                $this->queue->addDead($refundId, FailedEventQueue::ENDPOINT_REFUND, $idShop, $body, $result->error, $this->clock->now());
            });

            return self::OUTCOME_REFUSED;
        }
        $queued = $this->queueWrite($idOrder, $refundId, $scope, $result->error, function () use ($refundId, $idShop, $body, $result): void {
            $this->queue->enqueue($refundId, FailedEventQueue::ENDPOINT_REFUND, $idShop, $body, $result->error, $this->clock->now());
        });

        return $queued ? self::OUTCOME_PENDING : self::OUTCOME_REFUSED;
    }

    /**
     * The order's refund state after a dispatch. A refund that already reached AxiTrace (or waits in
     * the queue) whose local state cannot be written is recorded as such, not as a failed refund,
     * so nobody reports it a second time by hand.
     */
    private function afterDispatch(int $idOrder, ShopScope $scope, ?SentPurchase $sent, ?string $state, string $refundId, string $outcome): void
    {
        try {
            $this->updateRefundState($idOrder, $scope, $sent, $state);
        } catch (\Throwable $e) {
            if ($outcome !== self::OUTCOME_DELIVERED && $outcome !== self::OUTCOME_PENDING) {
                throw $e;
            }
            $this->failures->record(self::FAILURE_STATE_NOT_SAVED, sprintf(
                'The refund %s of order %d %s, but the order\'s refund state could not be updated in the shop (%s: %s). Do not report it again manually.',
                $refundId,
                $idOrder,
                $outcome === self::OUTCOME_DELIVERED ? 'WAS delivered to AxiTrace' : 'is queued and will be delivered',
                $e::class,
                $e->getMessage(),
            ), $scope);
        }
    }

    /** Runs one queue write; on failure releases the reservation, records the failure and returns false. */
    private function queueWrite(int $idOrder, string $refundId, ShopScope $scope, string $deliveryError, callable $write): bool
    {
        try {
            $write();

            return true;
        } catch (\Throwable $e) {
            $message = sprintf(
                'The refund %s of order %d was not delivered (%s) and could not be written to the retry queue (%s: %s); it is reported again only if PrestaShop triggers it again, otherwise report it manually.',
                $refundId,
                $idOrder,
                $deliveryError,
                $e::class,
                $e->getMessage(),
            );
            try {
                $this->ledger->release($refundId);
            } catch (\Throwable $releaseError) {
                $message .= sprintf(' Releasing its reservation failed too (%s: %s).', $releaseError::class, $releaseError->getMessage());
            }
            $this->failures->record(self::FAILURE_QUEUE_WRITE, $message, $scope);

            return false;
        }
    }

    private function updateRefundState(int $idOrder, ShopScope $scope, ?SentPurchase $sent, ?string $state): void
    {
        if ($state === null) {
            // The same base as a status refund: the sent purchase, else the current order total.
            $total = $this->stateBase($idOrder, $scope, $sent);
            $state = $total !== null && $total > 0.0 && $this->ledger->totalFor($idOrder) >= $total - self::EPSILON
                ? self::STATE_REFUNDED
                : self::STATE_PARTIALLY_REFUNDED;
        }

        $this->rows->setRefundState($idOrder, $state);
    }

    /**
     * The total a slip or line refund is compared with for the refund state: the sent purchase,
     * else the current order total, null when neither is known.
     */
    private function stateBase(int $idOrder, ShopScope $scope, ?SentPurchase $sent): ?float
    {
        if ($sent !== null) {
            return $sent->totalTaxIncl;
        }
        // Only the refunded / partially refunded label depends on it, never an amount: no failure.
        return $this->orders->read($idOrder)?->totalPaidTaxIncl;
    }

    /**
     * What a status refund starts from: the total the purchase carried. An order claimed before
     * 0.1.0 (or with an unreadable purchase snapshot) falls back to the order's current total,
     * recorded ({@see FAILURE_BASE_FALLBACK}) because PrestaShop may have changed it since.
     * Neither known: throws, so nothing is sent and the order is not marked refunded or cancelled.
     */
    private function refundBase(int $idOrder, ShopScope $scope, ?SentPurchase $sent, ?PurchaseOrder $order): float
    {
        if ($sent !== null) {
            return $sent->totalTaxIncl;
        }
        if ($order === null) {
            throw new \RuntimeException(sprintf('order %d: neither the sent purchase nor the order itself could be read, so the refunded amount is unknown', $idOrder));
        }

        $this->failures->record(self::FAILURE_BASE_FALLBACK, sprintf('order %d: the purchase snapshot is missing or unreadable; refunds are computed from the current order total %.2f %s, which PrestaShop may have changed since the purchase was sent.', $idOrder, $order->totalPaidTaxIncl, $order->currency), $scope);

        return $order->totalPaidTaxIncl;
    }

    /** The refund currency; throws when it is unknown (a refund without a currency is never sent). */
    private function currency(?SentPurchase $sent, int $idOrder): string
    {
        if ($sent !== null && $sent->currency !== '') {
            return $sent->currency;
        }

        $currency = $this->orders->read($idOrder)->currency ?? '';
        if ($currency === '') {
            throw new \RuntimeException(sprintf('order %d: the currency is unknown (no purchase snapshot and no readable order currency), so the refund is not sent', $idOrder));
        }

        return $currency;
    }

    private function guarded(int $idOrder, string $what, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $message = sprintf('reporting the %s of order %d failed (%s: %s)', $what, $idOrder, $e::class, $e->getMessage());
            try {
                $this->failures->record(self::FAILURE_CODE, $message, $this->scopeOf($idOrder));
            } catch (\Throwable $recordError) {
                $this->logger->error(sprintf('%s; recording the failure failed too (%s: %s)', $message, $recordError::class, $recordError->getMessage()));
            }
        }
    }

    /** The order's shop scope for the failure record; the current context when unknown. */
    private function scopeOf(int $idOrder): ShopScope
    {
        try {
            $row = $idOrder > 0 ? $this->rows->find($idOrder) : null;
        } catch (\Throwable) {
            $row = null;
        }

        return $row === null ? ShopScope::context() : ShopScope::shop((int) $row['id_shop_group'], (int) $row['id_shop']);
    }
}
