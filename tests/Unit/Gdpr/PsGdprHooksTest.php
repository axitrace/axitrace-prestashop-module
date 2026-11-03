<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Gdpr;

use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Gdpr\PsGdprHooks;
use Axitrace\PrestaShop\Purchase\OrderRowRepository;
use Axitrace\PrestaShop\Retry\FailedEventQueue;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeCustomerRecordsLocator;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryModuleDb;
use Axitrace\PrestaShop\Tests\Unit\Support\MutableClock;
use Axitrace\PrestaShop\Tests\Unit\Support\SpyLogger;
use PHPUnit\Framework\TestCase;

final class PsGdprHooksTest extends TestCase
{
    private InMemoryModuleDb $db;
    private InMemoryConfigurationStore $store;
    private FakeCustomerRecordsLocator $locator;
    private SpyLogger $logger;
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigurationStore();
        $this->db = new InMemoryModuleDb();
        $this->db->configuration = $this->store;
        $this->locator = new FakeCustomerRecordsLocator();
        $this->logger = new SpyLogger();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-11-04T10:00:00+00:00'));

        // Customer 5 (ada@example.com): order 10 (cart 100) and an abandoned cart 101.
        // Customer 6 (bob@example.com): order 1 (cart 200). Order ids 1 and 10 share a prefix on purpose.
        $this->locator->add(5, 'ada@example.com', '+48500100200', [10], [100, 101]);
        $this->locator->add(6, 'bob@example.com', '+48500999999', [1], [200]);

        $this->order(10, 'event-10', ['clientIp' => '203.0.113.5', 'userAgent' => 'Ada UA', 'visitorId' => 'vid-ada-0001', 'clickIds' => ['gclid' => 'g-ada'], 'consent' => 'granted']);
        $this->order(1, 'event-1', ['clientIp' => '198.51.100.6', 'userAgent' => 'Bob UA', 'visitorId' => 'vid-bob-0001']);
        $carts = new CartSnapshotRepository($this->db);
        $carts->save(100, 1, ['clientIp' => '203.0.113.5', 'visitorId' => 'vid-ada-0001'], 'h100', $this->clock->now());
        $carts->save(101, 1, ['clientIp' => '203.0.113.5', 'landingUrl' => 'https://shop.example/?gclid=g-ada'], 'h101', $this->clock->now());
        $carts->save(200, 1, ['clientIp' => '198.51.100.6'], 'h200', $this->clock->now());

        $queue = new FailedEventQueue($this->db, $this->store);
        $queue->enqueue('event-10', 'pixel', 1, ['orderId' => '10', 'data' => ['client' => ['email' => 'ada@example.com']]], 'HTTP 503', $this->clock->now());
        $queue->addDead('prestashop:10:slip:3', 'refund', 1, ['orderId' => '10', 'refundId' => 'prestashop:10:slip:3'], 'HTTP 400', $this->clock->now());
        $queue->enqueue('event-1', 'pixel', 1, ['orderId' => '1', 'data' => ['client' => ['email' => 'bob@example.com']]], 'HTTP 503', $this->clock->now());
        $queue->enqueue('prestashop:1:state:6', 'refund', 1, ['orderId' => '1'], 'HTTP 503', $this->clock->now());
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function order(int $idOrder, string $eventId, array $snapshot): void
    {
        (new OrderRowRepository($this->db))->insertNew($idOrder, 1, 1, 'REF' . $idOrder, $eventId, $snapshot, $this->clock->now());
        $this->db->orders[$idOrder]['purchase_claimed_at'] = '2026-11-01 10:00:00';
        $this->db->orders[$idOrder]['purchase_sent_at'] = '2026-11-01 10:00:01';
        $this->db->orders[$idOrder]['trigger'] = 'paid';
    }

    private function hooks(): PsGdprHooks
    {
        return new PsGdprHooks(
            $this->locator,
            new OrderRowRepository($this->db),
            new CartSnapshotRepository($this->db),
            new FailedEventQueue($this->db, $this->store),
            new FailureRecorder($this->store, $this->logger, $this->clock),
            $this->logger,
        );
    }

    /**
     * @param array<mixed> $params
     *
     * @return list<array<string, string>>
     */
    private function export(array $params): array
    {
        $decoded = json_decode($this->hooks()->export($params), true);
        self::assertIsArray($decoded);
        self::assertTrue(array_is_list($decoded));

        $rows = [];
        foreach ($decoded as $row) {
            self::assertIsArray($row);
            $rows[] = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $row);
        }

        return $rows;
    }

    public function testExportReturnsOnlyTheRowsOfTheGivenCustomer(): void
    {
        // psgdpr 1.4 / 2.x: (array) Customer.
        $rows = $this->export(['id' => '5', 'email' => 'ada@example.com', 'firstname' => 'Ada']);

        self::assertSame(
            [['order', '10'], ['queued_delivery', 'event-10'], ['queued_delivery', 'prestashop:10:slip:3'], ['cart_snapshot', '100'], ['cart_snapshot', '101']],
            array_map(static fn (array $r): array => [$r['record'], $r['id']], $rows),
        );
        $json = (string) json_encode($rows);
        self::assertStringContainsString('203.0.113.5', $json);
        self::assertStringContainsString('g-ada', $json);
        self::assertStringNotContainsString('198.51.100.6', $json, 'nothing of another customer');
        self::assertStringNotContainsString('bob@example.com', $json);
        self::assertStringNotContainsString('prestashop:1:', $json, 'order 1 is not order 10');
        foreach ($rows as $row) {
            self::assertSame(['record', 'id', 'recorded_at', 'content'], array_keys($row), 'one shape for the psgdpr table');
        }
        $order = json_decode($rows[0]['content'], true);
        self::assertSame('event-10', $order['event_id']);
        self::assertSame('2026-11-01 10:00:01', $order['purchase_sent_at']);
    }

    public function testExportByEmailOrPhone(): void
    {
        self::assertSame(['1', 'event-1', 'prestashop:1:state:6', '200'], array_column($this->export(['email' => 'bob@example.com']), 'id'));
        self::assertSame(['1', 'event-1', 'prestashop:1:state:6', '200'], array_column($this->export(['phone' => '+48500999999']), 'id'));
        self::assertSame([], $this->export(['email' => 'nobody@example.com']));
        self::assertSame([], $this->export(['something' => 'else']));
    }

    public function testDeleteBlanksTheOrderButKeepsTheSentFlags(): void
    {
        // psgdpr 2.x by customer id: [0 => '<id>'].
        self::assertSame('true', $this->hooks()->delete([0 => '5']));

        $row = $this->db->orders[10];
        self::assertSame('event-10', $row['event_id']);
        self::assertSame('2026-11-01 10:00:00', $row['purchase_claimed_at']);
        self::assertSame('2026-11-01 10:00:01', $row['purchase_sent_at']);
        self::assertSame('paid', $row['trigger']);
        self::assertSame(['consent' => 'granted'], json_decode((string) $row['snapshot'], true), 'only the consent decision stays');

        self::assertArrayNotHasKey(100, $this->db->cartSnapshots);
        self::assertArrayNotHasKey(101, $this->db->cartSnapshots);
        self::assertSame(['event-1', 'prestashop:1:state:6'], array_column($this->db->failedEvents, 'event_id'));

        // Customer 6 untouched.
        self::assertStringContainsString('198.51.100.6', (string) $this->db->orders[1]['snapshot']);
        self::assertArrayHasKey(200, $this->db->cartSnapshots);
        self::assertStringContainsString('GDPR erasure for customer 5: 1 orders blanked, 2 cart snapshots and 2 queued deliveries deleted.', implode("\n", $this->logger->infos));
    }

    public function testDeleteByEmailAndASnapshotWithoutConsentBecomesNull(): void
    {
        // psgdpr 2.x by e-mail: [0 => ['email' => ...]]; psgdpr 1.4: ['email' => ...].
        self::assertSame('true', $this->hooks()->delete([0 => ['email' => 'bob@example.com']]));

        self::assertNull($this->db->orders[1]['snapshot']);
        self::assertSame('2026-11-01 10:00:01', $this->db->orders[1]['purchase_sent_at']);
        self::assertArrayNotHasKey(200, $this->db->cartSnapshots);
        self::assertSame(['event-10', 'prestashop:10:slip:3'], array_column($this->db->failedEvents, 'event_id'));
        self::assertStringContainsString('203.0.113.5', (string) $this->db->orders[10]['snapshot']);
    }

    public function testUnknownParametersChangeNothing(): void
    {
        $before = [$this->db->orders, $this->db->cartSnapshots, $this->db->failedEvents];

        self::assertSame('true', $this->hooks()->delete([]));
        self::assertSame('true', $this->hooks()->delete([0 => 'not-a-customer']));
        self::assertSame([$this->db->orders, $this->db->cartSnapshots, $this->db->failedEvents], $before);
    }

    public function testAFailureIsVisibleAndNeverSwallowed(): void
    {
        $this->locator->failure = new \RuntimeException('Lost connection');

        $rows = $this->export(['id' => 5]);
        self::assertSame('error', $rows[0]['record']);
        self::assertStringContainsString('Lost connection', $rows[0]['content']);
        self::assertSame('false', $this->hooks()->delete([0 => '5']));
        self::assertSame(PsGdprHooks::FAILURE_CODE, (new FailureRecorder($this->store, $this->logger, $this->clock))->last(ShopScope::context())['code'] ?? null);
        self::assertNotSame([], $this->logger->errors);
    }
}
