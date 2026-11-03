<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Purchase;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\OrderReaderInterface;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\EventId\Ga4TransactionEventId;
use Axitrace\PrestaShop\Exclusion\CustomerGroupExclusion;
use Axitrace\PrestaShop\Normalizer\OrderEventNormalizer;
use Axitrace\PrestaShop\Snapshot\SnapshotBuilder;

/**
 * The browser purchase of the order confirmation page (A20): rendered only when the order counts as
 * a sale at render time - its current state is paid, or it was paid with a cash on delivery module
 * and is not failed or cancelled. A bank wire awaiting payment gets none; the server sends it once
 * the payment is accepted. Same event id as the server purchase, so AxiTrace keeps one.
 *
 * Whether the server already sent the order does not matter here: the shared event id is what
 * deduplicates the two.
 */
final class BrowserPurchase
{
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly OrderReaderInterface $orders,
        private readonly OrderRowRepository $rows,
        private readonly PurchaseEligibility $eligibility,
        private readonly CustomerGroupExclusion $exclusion,
        private readonly OrderEventNormalizer $normalizer,
    ) {
    }

    /**
     * @return array<string, mixed>|null the SDK `transaction.charge` parameters, eventSalt included
     */
    public function forOrder(int $idOrder): ?array
    {
        $order = $this->orders->read($idOrder);
        if ($order === null) {
            return null;
        }

        $scope = ShopScope::shop($order->idShopGroup, $order->idShop);
        if (!$this->config->isEnabled($scope) || $this->exclusion->excludes($order->customerDefaultGroupId, $scope)) {
            return null;
        }

        $row = $this->rows->find($idOrder);
        if ($row !== null && PurchaseLedger::isVoid($row)) {
            return null;
        }

        $decision = $this->eligibility->decide(
            $order->currentStateId,
            $order->currentStatePaid,
            $order->paymentModule,
            false,
            $this->config->codModules($scope),
            $this->orders->stateIds($order->idShopGroup, $order->idShop),
        );
        if (!$decision->isSend()) {
            return null;
        }

        $eventId = is_string($row['event_id'] ?? null) && trim($row['event_id']) !== ''
            ? trim($row['event_id'])
            : Ga4TransactionEventId::fromTransaction($this->config->publicKey($scope), (string) $idOrder);
        $snapshot = $row !== null ? SnapshotBuilder::decode((string) ($row['snapshot'] ?? '')) : null;

        return $this->normalizer->browserPurchase($order, $eventId, $this->config->valueBasis($scope), $snapshot);
    }
}
