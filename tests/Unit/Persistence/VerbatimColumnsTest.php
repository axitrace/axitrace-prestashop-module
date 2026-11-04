<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Persistence;

use Axitrace\PrestaShop\Purchase\OrderRowRepository;
use Axitrace\PrestaShop\Refund\SentLine;
use Axitrace\PrestaShop\Refund\SentPurchase;
use Axitrace\PrestaShop\Retry\FailedEventQueue;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;
use Axitrace\PrestaShop\Snapshot\SnapshotBuilder;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryModuleDb;
use PHPUnit\Framework\TestCase;

/**
 * JSON columns are stored verbatim: pSQL() without html_ok strips tags, which cut a payload holding
 * a product named "Ring <5mm>" short (and made it undecodable). The database double strips tags
 * the way PrestaShop does, so a JSON column written with escape() fails these round trips.
 */
final class VerbatimColumnsTest extends TestCase
{
    private const NAME = 'Ring <5mm> "gold" \\ 18k';

    public function testAQueuedPayloadKeepsAngleBrackets(): void
    {
        $db = new InMemoryModuleDb();
        $queue = new FailedEventQueue($db, new InMemoryConfigurationStore());
        $now = new \DateTimeImmutable('2026-11-04T10:00:00+00:00');
        $payload = ['eventSalt' => 'e1', 'items' => [['name' => self::NAME, 'price' => 10.0]]];

        $queue->enqueue('e1', FailedEventQueue::ENDPOINT_PIXEL, 1, $payload, 'HTTP 503', $now);

        $events = $queue->due($now->modify('+1 hour'), 10);
        self::assertCount(1, $events);
        self::assertSame($payload, $events[0]->decodedPayload());
    }

    public function testACartSnapshotKeepsAngleBrackets(): void
    {
        $db = new InMemoryModuleDb();
        $repository = new CartSnapshotRepository($db);
        $snapshot = ['consent' => 'granted', 'landing' => 'https://shop.example/?q=' . self::NAME];

        $repository->save(7, 1, $snapshot, SnapshotBuilder::hash($snapshot), new \DateTimeImmutable('2026-11-04T10:00:00+00:00'));

        self::assertSame($snapshot, $repository->find(7)['snapshot'] ?? null);
    }

    public function testTheOrderRowSnapshotAndSentPurchaseKeepAngleBrackets(): void
    {
        $db = new InMemoryModuleDb();
        $rows = new OrderRowRepository($db);
        $snapshot = ['consent' => 'granted', 'utm_content' => self::NAME];
        $rows->insertNew(5, 1, 1, 'REF', 'event', $snapshot, new \DateTimeImmutable('2026-11-04T10:00:00+00:00'));

        $sent = new SentPurchase('EUR', 49.9, [11 => new SentLine(11, 3, 0, self::NAME, 2, 24.95)]);
        $rows->recordSentPurchase(5, $sent);

        $row = $rows->find(5);
        self::assertNotNull($row);
        self::assertSame($snapshot, SnapshotBuilder::decode((string) $row['snapshot']));
        self::assertSame(self::NAME, SentPurchase::decode((string) $row['purchase_snapshot'])?->line(11)?->sku);

        $replaced = ['consent' => 'granted', 'note' => self::NAME];
        $rows->replaceSnapshot(5, $replaced);
        self::assertSame($replaced, SnapshotBuilder::decode((string) ($rows->find(5)['snapshot'] ?? '')));
    }

    public function testDisplayTextIsStillStrippedOfTags(): void
    {
        // The error column is display text: pSQL() as PrestaShop intends it.
        $db = new InMemoryModuleDb();
        $queue = new FailedEventQueue($db, new InMemoryConfigurationStore());
        $queue->enqueue('e2', FailedEventQueue::ENDPOINT_PIXEL, 1, ['eventSalt' => 'e2'], 'HTTP 502 <html>Bad gateway</html>', new \DateTimeImmutable('2026-11-04T10:00:00+00:00'));

        self::assertSame('HTTP 502 Bad gateway', array_values($db->failedEvents)[0]['last_error']);
    }
}
