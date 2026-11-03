<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Hook\Order;

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Consent\ConsentGate;
use Axitrace\PrestaShop\EventId\Ga4TransactionEventId;
use Axitrace\PrestaShop\Hook\Order\OrderPlacedHook;
use Axitrace\PrestaShop\Hook\Order\PlacedOrder;
use Axitrace\PrestaShop\Normalizer\ClickIdReader;
use Axitrace\PrestaShop\Purchase\OrderRowRepository;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;
use Axitrace\PrestaShop\Snapshot\SnapshotBuilder;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeRequest;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryModuleDb;
use Axitrace\PrestaShop\Tests\Unit\Support\MutableClock;
use Axitrace\PrestaShop\Tests\Unit\Support\SpyLogger;
use Axitrace\PrestaShop\Tests\Unit\Support\ThrowingDb;
use PHPUnit\Framework\TestCase;

final class OrderPlacedHookTest extends TestCase
{
    private const PK = 'pk_live_0123456789abcdef0123456789abcdef';

    private InMemoryConfigurationStore $store;
    private InMemoryModuleDb $db;
    private SpyLogger $logger;
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigurationStore();
        $this->store->setGlobal(ModuleConfig::ENABLED, '1');
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK);
        $this->db = new InMemoryModuleDb();
        $this->logger = new SpyLogger();
        $this->clock = new MutableClock();
    }

    public function testIpnOrderTakesTheCartSnapshot(): void
    {
        $cartSnapshot = ['clientIp' => '203.0.113.7', 'visitorId' => 'buyer-visitor'];
        (new CartSnapshotRepository($this->db))->save(25, 1, $cartSnapshot, SnapshotBuilder::hash($cartSnapshot), $this->clock->now());

        // The IPN request is the payment gateway's: no buyer cookies, another IP.
        $ipn = new FakeRequest([], [], '198.51.100.1', 'GatewayBot/1.0');
        $this->hook($ipn)->handle(new PlacedOrder(30, 1, 1, 25, 'REFABC'), false);

        $row = $this->db->orders[30];
        self::assertSame($cartSnapshot, SnapshotBuilder::decode((string) $row['snapshot']));
        self::assertSame(Ga4TransactionEventId::fromTransaction(self::PK, '30'), $row['event_id']);
        self::assertSame('REFABC', $row['reference']);
        self::assertSame('2026-11-03 12:00:00', $row['created_at']);
        self::assertSame([], $this->logger->errors);
    }

    public function testBuyerRequestWithoutCartSnapshotIsCaptured(): void
    {
        $buyer = new FakeRequest(['vt_vid' => 'buyer-visitor-1'], [], '203.0.113.9', 'Mozilla/5.0', 'https://shop.example/module/ps_wirepayment/validation');
        $this->hook($buyer)->handle(new PlacedOrder(31, 1, 1, 26, 'REFDEF'), true);

        $snapshot = SnapshotBuilder::decode((string) $this->db->orders[31]['snapshot']);
        self::assertNotNull($snapshot);
        self::assertSame('buyer-visitor-1', $snapshot['visitorId']);
        self::assertSame('203.0.113.9', $snapshot['clientIp']);
    }

    public function testBackOfficeOrderWithoutCartSnapshotGetsNone(): void
    {
        $employee = new FakeRequest(['vt_vid' => 'employee-visitor'], [], '192.0.2.1', 'Mozilla/5.0');
        $this->hook($employee)->handle(new PlacedOrder(32, 1, 1, 27, 'REFGHI'), false);

        self::assertNull($this->db->orders[32]['snapshot']);
        self::assertNotNull($this->db->orders[32]['event_id']);
    }

    /**
     * T1 fixture g_split_two_carriers (8.1.7 and 9.2.0): one cart, two orders sharing the
     * reference, one actionValidateOrder per order.
     */
    public function testSplitCartGivesTwoRowsWithDistinctEventIds(): void
    {
        foreach (['8.1.7', '9.2.0'] as $tag) {
            $this->setUp();
            $fixture = json_decode((string) file_get_contents(__DIR__ . "/../../../fixtures/ps/$tag/g_split_two_carriers.json"), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($fixture);
            $cartSnapshot = ['visitorId' => 'split-visitor'];
            $idCart = (int) $fixture['result']['id_cart'];
            (new CartSnapshotRepository($this->db))->save($idCart, 1, $cartSnapshot, SnapshotBuilder::hash($cartSnapshot), $this->clock->now());

            $hook = $this->hook(new FakeRequest());
            foreach ($fixture['result']['facts'] as $facts) {
                $order = $facts['order'][0];
                // 8.2.8 returns DB values as strings: cast like the adapter does.
                $hook->handle(new PlacedOrder((int) $order['id_order'], (int) $order['id_shop'], (int) $order['id_shop_group'], (int) $order['id_cart'], (string) $order['reference']), false);
            }

            self::assertCount(2, $this->db->orders, $tag);
            [$first, $second] = array_values($this->db->orders);
            self::assertSame($first['reference'], $second['reference'], $tag);
            self::assertNotSame($first['event_id'], $second['event_id'], $tag);
            self::assertSame(Ga4TransactionEventId::fromTransaction(self::PK, (string) $first['id_order']), $first['event_id']);
            self::assertSame($first['snapshot'], $second['snapshot'], 'both orders carry the cart snapshot');
        }
    }

    public function testRerunNeverOverwritesTheRow(): void
    {
        $hook = $this->hook(new FakeRequest(['vt_vid' => 'first-visitor']));
        $hook->handle(new PlacedOrder(33, 1, 1, 28, 'R'), true);
        $hook = $this->hook(new FakeRequest(['vt_vid' => 'other-visitor']));
        $hook->handle(new PlacedOrder(33, 1, 1, 28, 'R'), true);

        self::assertStringContainsString('first-visitor', (string) $this->db->orders[33]['snapshot']);
    }

    public function testDisabledShopRecordsNothing(): void
    {
        $this->store->set(ModuleConfig::ENABLED, '0', 2, 5);
        $this->hook(new FakeRequest())->handle(new PlacedOrder(34, 5, 2, 29, 'R'), false);

        self::assertSame([], $this->db->orders);
    }

    public function testAThrowingDatabaseNeverPropagatesAndIsLogged(): void
    {
        $this->hook(new FakeRequest(), new ThrowingDb())->handle(new PlacedOrder(35, 1, 1, 30, 'R'), true);

        self::assertCount(1, $this->logger->errors);
        self::assertStringContainsString('recording order 35 failed (RuntimeException: Database query failed: Lost connection)', $this->logger->errors[0]);
        $failure = (new FailureRecorder($this->store, new SpyLogger(), $this->clock))->last(ShopScope::shop(1, 1));
        self::assertNotNull($failure);
        self::assertSame('order_placed', $failure['code']);
    }

    private function hook(FakeRequest $request, ?DbGatewayInterface $db = null): OrderPlacedHook
    {
        $db ??= $this->db;
        $config = new ModuleConfig($this->store);

        return new OrderPlacedHook(
            $config,
            new CartSnapshotRepository($db),
            new OrderRowRepository($db),
            new SnapshotBuilder(new ClickIdReader(), new ConsentGate()),
            $request,
            new FailureRecorder($this->store, $this->logger, $this->clock),
            $this->logger,
            $this->clock,
        );
    }
}
