<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Hook\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\LoggerInterface;
use Axitrace\PrestaShop\Adapter\OrderReaderInterface;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Consent\ConsentGate;
use Axitrace\PrestaShop\EventId\Ga4TransactionEventId;
use Axitrace\PrestaShop\Exclusion\CustomerGroupExclusion;
use Axitrace\PrestaShop\Http\DeliveryOutcome;
use Axitrace\PrestaShop\Http\DeliveryResult;
use Axitrace\PrestaShop\Normalizer\OrderEventNormalizer;
use Axitrace\PrestaShop\Purchase\OrderRowRepository;
use Axitrace\PrestaShop\Purchase\PurchaseDecision;
use Axitrace\PrestaShop\Purchase\PurchaseEligibility;
use Axitrace\PrestaShop\Purchase\PurchaseLedger;
use Axitrace\PrestaShop\Purchase\PurchaseOrder;
use Axitrace\PrestaShop\Purchase\PurchaseSender;
use Axitrace\PrestaShop\Retry\FailedEventQueue;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;
use Axitrace\PrestaShop\Snapshot\SnapshotBuilder;

/**
 * `actionOrderStatusPostUpdate`: turns a state change into at most one server purchase per order.
 *
 * The hook fires for the initial state inside validateOrder (after actionValidateOrder recorded
 * the row), for every back-office or payment-module change, and again for a repeated state set
 * through OrderHistory. So every call goes: row (created here when actionValidateOrder missed the
 * order) -> enabled with a public key for the ORDER's shop -> eligibility -> customer group
 * exclusion and server consent (mode "all", fail-closed): both void the order -> atomic claim ->
 * normalize -> send synchronously (A21, 1 s / 2 s timeouts) -> SENT on 2xx. An undelivered
 * purchase keeps its claim and goes to the retry queue with the same event id (a refused one is
 * recorded there as dead, for the delivery log).
 *
 * Never throws: PrestaShop changes order states without a transaction. Every failure is logged
 * with class and message and recorded as the module's last failure.
 */
final class OrderStatusHook
{
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly OrderReaderInterface $orders,
        private readonly OrderRowRepository $rows,
        private readonly CartSnapshotRepository $cartSnapshots,
        private readonly PurchaseEligibility $eligibility,
        private readonly PurchaseLedger $ledger,
        private readonly CustomerGroupExclusion $exclusion,
        private readonly ConsentGate $consentGate,
        private readonly OrderEventNormalizer $normalizer,
        private readonly PurchaseSender $sender,
        private readonly FailedEventQueue $queue,
        private readonly FailureRecorder $failures,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function handle(StatusChange $change): void
    {
        $scope = ShopScope::context();

        try {
            $this->process($change, $scope);
        } catch (\Throwable $e) {
            $message = sprintf('handling the status change of order %d to state %d failed (%s: %s)', $change->idOrder, $change->newStateId, $e::class, $e->getMessage());
            try {
                $this->failures->record('order_status', $message, $scope);
            } catch (\Throwable $recordError) {
                $this->logger->error(sprintf('%s; recording the failure failed too (%s: %s)', $message, $recordError::class, $recordError->getMessage()));
            }
        }
    }

    private function process(StatusChange $change, ShopScope &$scope): void
    {
        if ($change->idOrder <= 0) {
            return;
        }

        $row = $this->rows->find($change->idOrder);
        if ($row !== null && PurchaseLedger::isClaimed($row)) {
            return;
        }

        $order = $this->orders->read($change->idOrder);
        if ($order === null) {
            return;
        }

        $scope = ShopScope::shop($order->idShopGroup, $order->idShop);
        if (!$this->config->isEnabled($scope)) {
            return;
        }

        $row ??= $this->createRow($order, $scope);

        $decision = $this->eligibility->decide(
            $change->newStateId,
            $change->newStatePaid,
            $order->paymentModule,
            PurchaseLedger::isClaimed($row),
            $this->config->codModules($scope),
            $this->orders->stateIds($order->idShopGroup, $order->idShop),
        );

        if ($decision === PurchaseDecision::Void) {
            $this->ledger->markVoid($order->idOrder);

            return;
        }
        if (!$decision->isSend()) {
            return;
        }

        $snapshot = SnapshotBuilder::decode((string) ($row['snapshot'] ?? ''));

        if ($this->exclusion->excludes($order->customerDefaultGroupId, $scope)) {
            $this->ledger->markVoid($order->idOrder);
            $this->logger->info(sprintf('order %d not sent: the customer group is excluded from tracking.', $order->idOrder));

            return;
        }

        $consent = $snapshot['consent'] ?? null;
        if (!$this->consentGate->allowsServerTracking($this->config->consentMode($scope), is_string($consent) ? $consent : null)) {
            $this->ledger->markVoid($order->idOrder);
            $this->logger->info(sprintf('order %d not sent: no consent recorded for server-side tracking.', $order->idOrder));

            return;
        }

        if (!$this->ledger->claim($order->idOrder, $decision, $this->clock->now())) {
            return;
        }

        try {
            $payload = $this->normalizer->normalize(
                $order,
                $this->eventId($row, $order, $scope),
                $this->config->publicKey($scope),
                $this->config->valueBasis($scope),
                $snapshot,
                $decision->value,
                $change->newStateId,
            );
        } catch (\Throwable $e) {
            // Nothing left the shop: give the claim back so the next status change tries again.
            $this->ledger->releaseClaim($order->idOrder);

            throw $e;
        }

        $result = $this->deliver($payload, $scope);
        if ($result->isSent()) {
            $this->ledger->markSent($order->idOrder, $this->clock->now());

            return;
        }

        $this->undelivered($order, $payload, $result, $scope);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function deliver(array $payload, ShopScope $scope): DeliveryResult
    {
        try {
            return $this->sender->send($payload, $scope);
        } catch (\Throwable $e) {
            // Whether the request left is unknown: the same event id makes a resend safe.
            $result = new DeliveryResult(DeliveryOutcome::Retry, null, sprintf('%s: %s', $e::class, mb_substr($e->getMessage(), 0, 200)));
            $this->failures->record(
                PurchaseSender::FAILURE_DELIVERY,
                sprintf('The purchase of order %s could not be delivered (%s); it is retried later.', is_scalar($payload['orderId'] ?? null) ? (string) $payload['orderId'] : '?', $result->error),
                $scope,
            );

            return $result;
        }
    }

    /**
     * The claim is kept: a later status change never sends the order again; the queue does.
     *
     * @param array<string, mixed> $payload
     */
    private function undelivered(PurchaseOrder $order, array $payload, DeliveryResult $result, ShopScope $scope): void
    {
        $eventId = is_string($payload['eventSalt'] ?? null) ? $payload['eventSalt'] : '';
        if ($result->outcome->isFinalFailure()) {
            $this->queue->addDead($eventId, FailedEventQueue::ENDPOINT_PIXEL, $order->idShop, $payload, $result->error, $this->clock->now());

            return;
        }

        $this->queue->enqueue($eventId, FailedEventQueue::ENDPOINT_PIXEL, $order->idShop, $payload, $result->error, $this->clock->now());
    }

    /**
     * @return array<string, mixed>
     */
    private function createRow(PurchaseOrder $order, ShopScope $scope): array
    {
        $this->rows->insertNew(
            $order->idOrder,
            $order->idShop,
            $order->idShopGroup,
            $order->reference,
            Ga4TransactionEventId::fromTransaction($this->config->publicKey($scope), (string) $order->idOrder),
            $this->cartSnapshots->find($order->idCart)['snapshot'] ?? null,
            $this->clock->now(),
        );

        $row = $this->rows->find($order->idOrder);
        if ($row === null) {
            throw new \RuntimeException(sprintf('The axitrace_order row of order %d could not be created.', $order->idOrder));
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function eventId(array $row, PurchaseOrder $order, ShopScope $scope): string
    {
        $eventId = is_string($row['event_id'] ?? null) ? trim($row['event_id']) : '';

        return $eventId !== '' ? $eventId : Ga4TransactionEventId::fromTransaction($this->config->publicKey($scope), (string) $order->idOrder);
    }
}
