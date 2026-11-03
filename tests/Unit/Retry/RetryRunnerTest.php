<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Retry;

use Axitrace\PrestaShop\Adapter\HttpResponse;
use Axitrace\PrestaShop\Config\EndpointResolver;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Http\IngestionClient;
use Axitrace\PrestaShop\Purchase\PurchaseLedger;
use Axitrace\PrestaShop\Retry\FailedEventQueue;
use Axitrace\PrestaShop\Retry\RetryRunner;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeEnvironment;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHostResolver;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHttpTransport;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeShopGroupResolver;
use Axitrace\PrestaShop\Tests\Unit\Support\Fixtures;
use Axitrace\PrestaShop\Tests\Unit\Support\PurchasePipeline;
use Axitrace\PrestaShop\Tests\Unit\Support\TickingClock;
use PHPUnit\Framework\TestCase;

final class RetryRunnerTest extends TestCase
{
    private const SK_SHOP_2 = 'sk_live_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * An order whose first attempt timed out: claimed, unsent, one queued row due in 5 minutes.
     *
     * @param array<string, mixed> $changes
     */
    private function failedPurchase(PurchasePipeline $pipeline, string $capture = 'k_paid_at_creation', array $changes = []): int
    {
        $order = Fixtures::orders('8.1.7', $capture)[0];
        if ($changes !== []) {
            $order = Fixtures::with($order, $changes);
        }
        $pipeline->place($order);
        $pipeline->transport->willAnswer(FakeHttpTransport::timeout());
        $pipeline->status($order->idOrder, 2, '1');

        return $order->idOrder;
    }

    public function testTimedOutPurchaseIsQueuedWithItsEventIdAndDeliveredAfterTheBackoff(): void
    {
        $pipeline = new PurchasePipeline();
        $idOrder = $this->failedPurchase($pipeline);
        $first = $pipeline->transport->requests[0]['payload'];

        $row = array_values($pipeline->db->failedEvents)[0];
        self::assertSame($first['eventSalt'], $row['event_id']);
        self::assertSame('pixel', $row['endpoint']);
        self::assertSame('1', (string) $row['id_shop']);
        self::assertSame('2026-11-03 22:05:00', $row['next_attempt_at']);

        // Not due yet.
        $report = $pipeline->runner()->run(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS);
        self::assertSame(0, $report->processed);

        $pipeline->clock->now = $pipeline->clock->now->modify('+5 minutes');
        $report = $pipeline->runner()->run(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS);

        self::assertSame('processed=1 delivered=1 failed=0 dead=0 dropped=0', $report->summary());
        self::assertCount(2, $pipeline->transport->requests);
        self::assertSame($first, $pipeline->transport->requests[1]['payload'], 'the stored request, byte for byte');
        self::assertSame([], $pipeline->db->failedEvents);
        self::assertNotNull($pipeline->db->orders[$idOrder]['purchase_sent_at']);
        self::assertNull($pipeline->queue()->dueAt());
    }

    public function testRepeatedFailuresBackOffThenDieAfterTenAttempts(): void
    {
        $pipeline = new PurchasePipeline();
        $this->failedPurchase($pipeline);
        $expectedWaits = [600, 1200, 2400, 4800, 9600, 19200, 21600, 21600];

        $now = $pipeline->clock->now->modify('+5 minutes');
        foreach ($expectedWaits as $i => $wait) {
            $pipeline->clock->now = $now;
            $pipeline->transport->willAnswer(new HttpResponse(503));
            $report = $pipeline->runner()->run(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS);
            self::assertSame(1, $report->failed, 'attempt ' . ($i + 2));
            $row = array_values($pipeline->db->failedEvents)[0];
            self::assertSame(FailedEventQueue::utc($now->modify("+$wait seconds")), $row['next_attempt_at'], 'attempt ' . ($i + 2));
            self::assertSame($now->modify("+$wait seconds")->getTimestamp(), $pipeline->queue()->dueAt());
            $now = $now->modify("+$wait seconds");
        }

        $pipeline->clock->now = $now;
        $pipeline->transport->willAnswer(new HttpResponse(503));
        $report = $pipeline->runner()->run(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS);

        self::assertSame(1, $report->dead);
        $row = array_values($pipeline->db->failedEvents)[0];
        self::assertSame('dead', $row['status']);
        self::assertSame('10', (string) $row['attempts']);
        self::assertCount(10, $pipeline->transport->requests);
        self::assertNull($pipeline->queue()->dueAt());
        self::assertStringContainsString('given up after 10 attempts', implode("\n", $pipeline->logger->errors));
    }

    public function testRefusedRetryIsDeadAtOnce(): void
    {
        $pipeline = new PurchasePipeline();
        $this->failedPurchase($pipeline);
        $pipeline->clock->now = $pipeline->clock->now->modify('+5 minutes');
        $pipeline->transport->willAnswer(new HttpResponse(400, [], 'eventSalt required'));

        $report = $pipeline->runner()->run(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS);

        self::assertSame(1, $report->dead);
        self::assertSame('HTTP 400: eventSalt required', array_values($pipeline->db->failedEvents)[0]['last_error']);
    }

    public function testRefusedFirstAttemptIsRecordedDeadByTheHook(): void
    {
        $pipeline = new PurchasePipeline();
        $order = Fixtures::orders('9.2.0', 'k_paid_at_creation')[0];
        $pipeline->place($order);
        $pipeline->transport->willAnswer(new HttpResponse(401, [], 'Invalid workspace_public_key'));
        $pipeline->status($order->idOrder, 2, 1);

        $row = array_values($pipeline->db->failedEvents)[0];
        self::assertSame('dead', $row['status']);
        self::assertSame('HTTP 401: Invalid workspace_public_key', $row['last_error']);
        self::assertNull($pipeline->queue()->dueAt(), 'nothing to retry');
    }

    public function testEveryRowIsSentWithItsOwnShopsKeyAndEndpoint(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->shopGroups = [1 => 1, 2 => 2];
        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        $pipeline->store->set(ModuleConfig::PUBLIC_KEY, 'pk_live_' . str_repeat('b', 32), 2, 2);
        $pipeline->vault()->store(self::SK_SHOP_2, ShopScope::shop(2, 2));
        $this->failedPurchase($pipeline, 'k_paid_at_creation');
        $order2 = Fixtures::with(Fixtures::orders('9.2.0', 'b1_wire_order')[0], ['idShop' => 2, 'idShopGroup' => 2]);
        $pipeline->place($order2);
        $pipeline->transport->willAnswer(FakeHttpTransport::timeout());
        $pipeline->status($order2->idOrder, 2, '1');
        self::assertCount(2, $pipeline->db->failedEvents);

        $pipeline->clock->now = $pipeline->clock->now->modify('+5 minutes');
        $report = $pipeline->runner()->run(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS);

        self::assertSame(2, $report->delivered);
        $retries = array_slice($pipeline->transport->requests, 2);
        $auth = [];
        foreach ($retries as $request) {
            $auth[$request['payload']['orderId']] = $request['headers']['Authorization'] ?? '';
        }
        self::assertSame('Basic ' . base64_encode(PurchasePipeline::SK . ':'), $auth['30']);
        self::assertSame('Basic ' . base64_encode(self::SK_SHOP_2 . ':'), $auth['7']);
    }

    public function testTheSecretKeyIsNeverStoredInTheQueue(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        $this->failedPurchase($pipeline);
        $pipeline->queue()->addDead('x', 'refund', 1, ['refundId' => 'prestashop:30:slip:1'], 'HTTP 400', $pipeline->clock->now);

        foreach ($pipeline->db->failedEvents as $row) {
            self::assertStringNotContainsString(PurchasePipeline::SK, (string) json_encode($row));
            self::assertStringNotContainsString(base64_encode(PurchasePipeline::SK . ':'), (string) json_encode($row));
            self::assertStringNotContainsString('sk_live_', (string) $row['payload']);
        }
        self::assertNotSame([], $pipeline->db->failedEvents);
    }

    public function testBudgetStopsTheBatch(): void
    {
        $pipeline = new PurchasePipeline();
        $now = $pipeline->clock->now;
        for ($i = 1; $i <= 5; ++$i) {
            $pipeline->queue()->enqueue('event-' . $i, 'pixel', 1, ['event' => 'transaction.charge', 'orderId' => (string) $i], 'HTTP 503', $now->modify('-1 hour'));
        }
        $ticking = new TickingClock($now, 0.4);
        $runner = new RetryRunner(
            $pipeline->queue(),
            $pipeline->sender(),
            $pipeline->refundSender(),
            new PurchaseLedger($pipeline->db),
            $pipeline->refundLedger(),
            new CartSnapshotRepository($pipeline->db),
            new FakeShopGroupResolver(),
            $pipeline->logger,
            $ticking,
        );

        $report = $runner->run(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS);

        self::assertTrue($report->budgetExhausted);
        self::assertLessThan(5, $report->processed);
        self::assertGreaterThan(0, $report->processed);
        self::assertCount(5 - $report->processed, $pipeline->db->failedEvents);
        self::assertNotNull($pipeline->queue()->dueAt(), 'the rest stays due');
    }

    public function testBatchSizeIsHonoured(): void
    {
        $pipeline = new PurchasePipeline();
        for ($i = 1; $i <= 25; ++$i) {
            $pipeline->queue()->enqueue('event-' . $i, 'pixel', 1, ['event' => 'transaction.charge', 'orderId' => (string) $i], 'x', $pipeline->clock->now->modify('-1 hour'));
        }

        self::assertSame(20, $pipeline->runner()->run(RetryRunner::LAZY_BATCH, 60.0)->processed);
        self::assertSame(5, $pipeline->runner()->run(RetryRunner::CRON_BATCH, 60.0)->processed);
    }

    public function testRefundRowsNeedTheKeyAndAreDroppedWhenItIsRejected(): void
    {
        $pipeline = new PurchasePipeline();
        $past = $pipeline->clock->now->modify('-1 hour');
        $pipeline->queue()->enqueue('refund-1', 'refund', 1, ['refundId' => 'prestashop:30:slip:1', 'amount' => 10.0], 'HTTP 503', $past);

        $report = $pipeline->runner()->run(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS);
        self::assertSame(1, $report->failed, 'no key: a failed attempt, nothing sent');
        self::assertCount(0, $pipeline->transport->requests);
        self::assertStringContainsString('no secret key configured', (string) array_values($pipeline->db->failedEvents)[0]['last_error']);

        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        $pipeline->clock->now = $pipeline->clock->now->modify('+1 day');
        $pipeline->transport->willAnswer(new HttpResponse(401));
        $report = $pipeline->runner()->run(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS);

        self::assertSame(1, $report->dropped);
        self::assertSame([], $pipeline->db->failedEvents);
        self::assertSame(PurchasePipeline::SINK . IngestionClient::REFUND_PATH, $pipeline->transport->requests[0]['url']);
        self::assertStringContainsString('the refund refund-1 was dropped', implode("\n", $pipeline->logger->errors));
    }

    public function testRefundRowIsDeliveredWithTheKey(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        $pipeline->queue()->enqueue('refund-2', 'refund', 1, ['refundId' => 'prestashop:30:slip:2'], 'HTTP 503', $pipeline->clock->now->modify('-1 hour'));

        self::assertSame(1, $pipeline->runner()->run(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS)->delivered);
        self::assertSame('Basic ' . base64_encode(PurchasePipeline::SK . ':'), $pipeline->transport->requests[0]['headers']['Authorization']);
    }

    public function testCorruptRowIsDeadAndCartSnapshotsArePruned(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->queue()->enqueue('x', 'pixel', 1, [], 'x', $pipeline->clock->now->modify('-1 hour'));
        $id = (int) array_key_first($pipeline->db->failedEvents);
        $pipeline->db->failedEvents[$id]['payload'] = '{not json';
        $snapshots = new CartSnapshotRepository($pipeline->db);
        $snapshots->save(1, 1, ['visitorId' => 'old'], 'h1', $pipeline->clock->now->modify('-31 days'));
        $snapshots->save(2, 1, ['visitorId' => 'new'], 'h2', $pipeline->clock->now->modify('-1 day'));

        $report = $pipeline->runner()->run(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS);

        self::assertSame(1, $report->dead);
        self::assertSame([2], array_keys($pipeline->db->cartSnapshots));
    }
}
