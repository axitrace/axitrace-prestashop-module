<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Hook\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\LoggerInterface;
use Axitrace\PrestaShop\Adapter\RequestContextInterface;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\EventId\Ga4TransactionEventId;
use Axitrace\PrestaShop\Purchase\OrderRowRepository;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;
use Axitrace\PrestaShop\Snapshot\SnapshotBuilder;

/**
 * `actionValidateOrder`: records the order in `axitrace_order` (state NEW) with its
 * deterministic event id and the attribution snapshot - the cart's snapshot when the buyer
 * reached the cart or checkout pages, else the current request when it is the buyer's own
 * (a payment module's validation controller). An IPN or back-office order without a cart
 * snapshot gets no snapshot: their request is not the buyer's.
 *
 * Split carts (several orders from one cart) get one row per id_order, each with its own event id
 * and a copy of the same snapshot (A4).
 *
 * Never throws: PrestaShop saves orders without a transaction, so an exception here would leave a
 * half-created order. Failures are logged with class and message and recorded as the module's
 * last failure.
 */
final class OrderPlacedHook
{
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly CartSnapshotRepository $cartSnapshots,
        private readonly OrderRowRepository $orders,
        private readonly SnapshotBuilder $builder,
        private readonly RequestContextInterface $request,
        private readonly FailureRecorder $failures,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param bool $isBuyerRequest the current request is the buyer's own storefront request for
     *                             this order's cart
     */
    public function handle(PlacedOrder $order, bool $isBuyerRequest): void
    {
        $scope = ShopScope::shop($order->idShopGroup, $order->idShop);

        try {
            $this->record($order, $scope, $isBuyerRequest);
        } catch (\Throwable $e) {
            $message = sprintf('recording order %d failed (%s: %s)', $order->idOrder, $e::class, $e->getMessage());
            try {
                $this->failures->record('order_placed', $message, $scope);
            } catch (\Throwable $recordError) {
                $this->logger->error(sprintf('%s; recording the failure failed too (%s: %s)', $message, $recordError::class, $recordError->getMessage()));
            }
        }
    }

    private function record(PlacedOrder $order, ShopScope $scope, bool $isBuyerRequest): void
    {
        if (!$this->config->isEnabled($scope)) {
            return;
        }

        $now = $this->clock->now();
        $snapshot = $this->cartSnapshots->find($order->idCart)['snapshot'] ?? null;
        if ($snapshot === null && $isBuyerRequest) {
            $snapshot = $this->builder->build($this->request, $this->config->consentCookie($scope), (int) $now->format('Uv'));
        }

        $this->orders->insertNew(
            $order->idOrder,
            $order->idShop,
            $order->idShopGroup,
            $order->reference,
            Ga4TransactionEventId::fromTransaction($this->config->publicKey($scope), (string) $order->idOrder),
            $snapshot,
            $now,
        );
    }
}
