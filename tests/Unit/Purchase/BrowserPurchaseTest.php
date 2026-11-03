<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Purchase;

use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\EventId\Ga4TransactionEventId;
use Axitrace\PrestaShop\Purchase\PurchaseLedger;
use Axitrace\PrestaShop\Tests\Unit\Support\Fixtures;
use Axitrace\PrestaShop\Tests\Unit\Support\PurchasePipeline;
use PHPUnit\Framework\TestCase;

final class BrowserPurchaseTest extends TestCase
{
    public function testPaidAndCodOrdersGetABrowserPurchaseWithTheServerEventId(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            foreach (['k_paid_at_creation', 'a_cod_order'] as $capture) {
                $pipeline = new PurchasePipeline();
                $order = Fixtures::orders($tag, $capture)[0];
                $pipeline->place($order, ['fbp' => 'fb.1.1793742000000.1234567890']);

                $purchase = $pipeline->browserPurchase()->forOrder($order->idOrder);

                self::assertNotNull($purchase, "$tag $capture");
                self::assertSame(Ga4TransactionEventId::fromTransaction(PurchasePipeline::PK, (string) $order->idOrder), $purchase['eventSalt']);
                self::assertSame((string) $order->idOrder, $purchase['transactionId']);
                self::assertSame('fb.1.1793742000000.1234567890', $purchase['fbp']);
            }
        }
    }

    public function testBankWireAwaitingPaymentGetsNone(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = new PurchasePipeline();
            $order = Fixtures::orders($tag, 'b1_wire_order')[0];
            $pipeline->place($order);

            self::assertNull($pipeline->browserPurchase()->forOrder($order->idOrder), $tag);
        }
    }

    public function testExcludedVoidAndDisabledGetNone(): void
    {
        $order = Fixtures::orders('9.2.0', 'k_paid_at_creation')[0];

        $pipeline = new PurchasePipeline();
        $pipeline->store->setGlobal(ModuleConfig::EXCLUDED_GROUPS, '3');
        $pipeline->place($order);
        self::assertNull($pipeline->browserPurchase()->forOrder($order->idOrder));

        $pipeline = new PurchasePipeline();
        $pipeline->place($order);
        (new PurchaseLedger($pipeline->db))->markVoid($order->idOrder);
        self::assertNull($pipeline->browserPurchase()->forOrder($order->idOrder));

        $pipeline = new PurchasePipeline();
        $pipeline->store->setGlobal(ModuleConfig::ENABLED, '0');
        $pipeline->place($order);
        self::assertNull($pipeline->browserPurchase()->forOrder($order->idOrder));

        self::assertNull((new PurchasePipeline())->browserPurchase()->forOrder(404));
    }

    public function testOrderWithoutRowStillGetsTheDeterministicEventId(): void
    {
        $pipeline = new PurchasePipeline();
        $order = Fixtures::orders('8.1.7', 'k_paid_at_creation')[0];
        $pipeline->reader->add($order);

        self::assertSame(Ga4TransactionEventId::fromTransaction(PurchasePipeline::PK, '30'), $pipeline->browserPurchase()->forOrder(30)['eventSalt'] ?? null);
    }
}
