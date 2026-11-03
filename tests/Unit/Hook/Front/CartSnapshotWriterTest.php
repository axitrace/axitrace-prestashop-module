<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Hook\Front;

use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Consent\ConsentGate;
use Axitrace\PrestaShop\Hook\Front\CartSnapshotWriter;
use Axitrace\PrestaShop\Normalizer\ClickIdReader;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;
use Axitrace\PrestaShop\Snapshot\SnapshotBuilder;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeRequest;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryModuleDb;
use Axitrace\PrestaShop\Tests\Unit\Support\MutableClock;
use PHPUnit\Framework\TestCase;

final class CartSnapshotWriterTest extends TestCase
{
    private InMemoryConfigurationStore $store;
    private InMemoryModuleDb $db;
    private FakeRequest $request;
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigurationStore();
        $this->store->setGlobal(ModuleConfig::ENABLED, '1');
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, 'pk_live_0123456789abcdef0123456789abcdef');
        $this->db = new InMemoryModuleDb();
        $this->request = new FakeRequest(['vt_vid' => 'visitor-0001', '_fbp' => 'fb.1.1700000000000.42'], ['gclid' => 'G-1'], '203.0.113.7', 'UA', 'https://shop.example/cart');
        $this->clock = new MutableClock();
    }

    public function testWrittenOncePerChange(): void
    {
        $writer = $this->writer();

        self::assertTrue($writer->write('cart', 7, 1, ShopScope::context()));
        $this->clock->now = $this->clock->now->modify('+1 minute');
        self::assertFalse($writer->write('order', 7, 1, ShopScope::context()), 'same content: no write');
        self::assertCount(1, $this->db->executed);

        $this->request->cookies['_fbc'] = 'fb.1.1700000000000.IwAR';
        self::assertTrue($writer->write('order', 7, 1, ShopScope::context()), 'changed content: written');
        self::assertCount(2, $this->db->executed);

        $row = $this->db->cartSnapshots[7];
        $snapshot = SnapshotBuilder::decode((string) $row['snapshot']);
        self::assertNotNull($snapshot);
        self::assertSame('fb.1.1700000000000.IwAR', $snapshot['fbc']);
        self::assertSame(SnapshotBuilder::hash($snapshot), $row['hash']);
        self::assertSame('2026-11-03 12:01:00', $row['updated_at']);
    }

    public function testClickIdsOfEarlierRequestsAreKept(): void
    {
        $writer = $this->writer();
        $writer->write('cart', 7, 1, ShopScope::context());

        $this->request->query = ['fbclid' => 'IwAR-later'];
        $writer->write('order', 7, 1, ShopScope::context());

        $snapshot = SnapshotBuilder::decode((string) $this->db->cartSnapshots[7]['snapshot']);
        self::assertNotNull($snapshot);
        self::assertSame(['fbclid' => 'IwAR-later', 'gclid' => 'G-1'], $snapshot['clickIds']);
    }

    public function testOnlyCartAndCheckoutPagesOfAnEnabledShop(): void
    {
        $writer = $this->writer();
        self::assertFalse($writer->write('product', 7, 1, ShopScope::context()));
        self::assertFalse($writer->write('order-confirmation', 7, 1, ShopScope::context()));
        self::assertFalse($writer->write('cart', 0, 1, ShopScope::context()), 'no cart yet');

        $this->store->setGlobal(ModuleConfig::ENABLED, '0');
        self::assertFalse($writer->write('cart', 7, 1, ShopScope::context()));
        self::assertSame([], $this->db->executed);
    }

    public function testPruneDeletesSnapshotsOlderThan30Days(): void
    {
        $writer = $this->writer();
        $writer->write('cart', 1, 1, ShopScope::context());
        $this->clock->now = $this->clock->now->modify('+20 days');
        $writer->write('cart', 2, 1, ShopScope::context());

        (new CartSnapshotRepository($this->db))->prune($this->clock->now->modify('+11 days'));

        self::assertSame([2], array_keys($this->db->cartSnapshots));
    }

    private function writer(): CartSnapshotWriter
    {
        return new CartSnapshotWriter(
            new ModuleConfig($this->store),
            new SnapshotBuilder(new ClickIdReader(), new ConsentGate()),
            new CartSnapshotRepository($this->db),
            $this->request,
            $this->clock,
        );
    }
}
