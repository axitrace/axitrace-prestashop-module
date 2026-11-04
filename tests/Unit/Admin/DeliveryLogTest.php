<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Admin;

use Axitrace\PrestaShop\Adapter\HttpResponse;
use Axitrace\PrestaShop\Admin\DeliveryLog;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Purchase\OrderRowRepository;
use Axitrace\PrestaShop\Tests\Unit\Support\EchoTranslator;
use Axitrace\PrestaShop\Tests\Unit\Support\PurchasePipeline;
use PHPUnit\Framework\TestCase;

final class DeliveryLogTest extends TestCase
{
    private static function log(PurchasePipeline $pipeline): DeliveryLog
    {
        return new DeliveryLog($pipeline->queue(), $pipeline->lazyTrigger(), new EchoTranslator(), $pipeline->clock);
    }

    public function testListsQueuedAndFailedRowsNewestFirst(): void
    {
        $pipeline = new PurchasePipeline();
        $now = $pipeline->clock->now();
        $pipeline->queue()->enqueue('purchase-event', 'pixel', 1, ['event' => 'transaction.charge', 'orderId' => '30'], 'HTTP 503', $now);
        $pipeline->queue()->addDead('prestashop:30:slip:1', 'refund', 1, ['refundId' => 'prestashop:30:slip:1'], 'HTTP 400: currency required', $now);

        $entries = self::log($pipeline)->entries();

        self::assertSame(['prestashop:30:slip:1', 'purchase-event'], array_column($entries, 'event_id'));
        self::assertSame(['Refund', 'Purchase'], array_column($entries, 'type'));
        self::assertSame(['Failed', 'Waiting for a retry'], array_column($entries, 'status'));
        self::assertSame([true, false], array_column($entries, 'failed'));
        self::assertSame('HTTP 400: currency required', $entries[0]['last_error']);
        self::assertSame('', $entries[0]['next_attempt_at'], 'a failed row is never attempted again');
        self::assertSame('2026-11-03 22:05:00', $entries[1]['next_attempt_at']);
        self::assertSame(1, $entries[1]['attempts']);
    }

    public function testShowsAtMostFiftyRows(): void
    {
        $pipeline = new PurchasePipeline();
        for ($i = 1; $i <= 60; ++$i) {
            $pipeline->queue()->addDead('e' . $i, 'pixel', 1, ['orderId' => (string) $i], 'HTTP 400', $pipeline->clock->now());
        }

        $entries = self::log($pipeline)->entries();
        self::assertCount(DeliveryLog::LIMIT, $entries);
        self::assertSame('e60', $entries[0]['event_id']);
    }

    public function testRetryNowSendsTheQueuedRowsBeforeTheyAreDue(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        $now = $pipeline->clock->now();
        (new OrderRowRepository($pipeline->db))->insertNew(30, 1, 1, 'REF30', 'purchase-event', null, $now);
        $pipeline->queue()->enqueue('purchase-event', 'pixel', 1, ['event' => 'transaction.charge', 'orderId' => '30'], 'HTTP 503', $now);
        $pipeline->queue()->enqueue('prestashop:30:slip:1', 'refund', 1, ['refundId' => 'prestashop:30:slip:1', 'orderId' => '30', 'amount' => 5.0, 'currency' => 'EUR'], 'HTTP 503', $now);
        $pipeline->queue()->addDead('dead-event', 'pixel', 1, ['orderId' => '31'], 'HTTP 400', $now);
        $pipeline->transport->willAnswer(new HttpResponse(202), new HttpResponse(503));

        $log = self::log($pipeline);
        $report = $log->retryNow();

        self::assertNotNull($report);
        self::assertSame(2, $report->processed, 'both queued rows, 5 minutes before they were due; never the failed one');
        self::assertSame(1, $report->delivered);
        self::assertSame(1, $report->failed);
        self::assertSame('Retried 2 deliveries: 1 delivered, 1 still failing, 0 failed for good, 0 waiting for a valid secret key.', $log->summary($report));
        self::assertSame('2026-11-03 22:00:00', $pipeline->db->orders[30]['purchase_sent_at'] ?? '', 'the delivered purchase marks its order sent');
        self::assertSame(['prestashop:30:slip:1', 'dead-event'], array_column($pipeline->db->failedEvents, 'event_id'));
    }

    public function testRetryNowWithNothingQueuedAndWhileLocked(): void
    {
        $pipeline = new PurchasePipeline();
        $log = self::log($pipeline);
        self::assertSame('Nothing is waiting for a retry.', $log->summary($log->retryNow()));
        self::assertSame([], $pipeline->transport->requests);

        $pipeline->queue()->enqueue('purchase-event', 'pixel', 1, ['orderId' => '30'], 'HTTP 503', $pipeline->clock->now());
        self::assertTrue($pipeline->lock()->acquire($pipeline->clock->now()));
        $report = $log->retryNow();
        self::assertNull($report);
        self::assertSame('Another retry run is in progress. Try again in a minute.', $log->summary($report));
    }
}
