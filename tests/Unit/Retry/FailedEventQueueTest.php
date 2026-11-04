<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Retry;

use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Retry\FailedEventQueue;
use Axitrace\PrestaShop\Tests\Unit\Support\PurchasePipeline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailedEventQueueTest extends TestCase
{
    private const EVENT_ID = '2fe9524b-2b1a-52a6-beb3-592eda17a639';

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function schedule(): iterable
    {
        // min(5 min x 2^(n-1), 6 h)
        yield 'after attempt 1' => [1, 300];
        yield 'after attempt 2' => [2, 600];
        yield 'after attempt 3' => [3, 1200];
        yield 'after attempt 4' => [4, 2400];
        yield 'after attempt 5' => [5, 4800];
        yield 'after attempt 6' => [6, 9600];
        yield 'after attempt 7' => [7, 19200];
        yield 'after attempt 8 (capped)' => [8, 21600];
        yield 'after attempt 9 (capped)' => [9, 21600];
        yield 'far beyond' => [64, 21600];
        yield 'zero is treated as the first' => [0, 300];
    }

    #[DataProvider('schedule')]
    public function testBackoffSchedule(int $attempts, int $seconds): void
    {
        self::assertSame($seconds, FailedEventQueue::backoffSeconds($attempts));
    }

    public function testEnqueueWaitsFiveMinutesAndMarksTheQueueDue(): void
    {
        $pipeline = new PurchasePipeline();
        $now = new \DateTimeImmutable('2026-11-03T22:00:00+00:00');

        $pipeline->queue()->enqueue(self::EVENT_ID, 'pixel', 1, ['eventSalt' => self::EVENT_ID], 'HTTP 503', $now);

        $row = array_values($pipeline->db->failedEvents)[0];
        self::assertSame('1', $row['attempts']);
        self::assertSame('queued', $row['status']);
        self::assertSame('2026-11-03 22:05:00', $row['next_attempt_at']);
        self::assertSame('HTTP 503', $row['last_error']);
        self::assertSame((string) $now->modify('+300 seconds')->getTimestamp(), $pipeline->store->get(ModuleConfig::RETRY_DUE_AT));
    }

    public function testTheSameEventIsQueuedOnce(): void
    {
        $pipeline = new PurchasePipeline();
        $now = new \DateTimeImmutable('2026-11-03T22:00:00+00:00');

        $pipeline->queue()->enqueue(self::EVENT_ID, 'pixel', 1, [], 'x', $now);
        $pipeline->queue()->enqueue(self::EVENT_ID, 'pixel', 1, [], 'x', $now);
        $pipeline->queue()->enqueue(self::EVENT_ID, 'refund', 1, [], 'x', $now);

        self::assertCount(2, $pipeline->db->failedEvents);
    }

    public function testDueAtOnlyMovesEarlier(): void
    {
        $pipeline = new PurchasePipeline();
        $queue = $pipeline->queue();
        $queue->markDueBy(new \DateTimeImmutable('@2000'));
        $queue->markDueBy(new \DateTimeImmutable('@3000'));
        self::assertSame(2000, $queue->dueAt());
        $queue->markDueBy(new \DateTimeImmutable('@1000'));
        self::assertSame(1000, $queue->dueAt());
    }

    public function testTenthFailedAttemptMakesTheRowDead(): void
    {
        $pipeline = new PurchasePipeline();
        $queue = $pipeline->queue();
        $now = new \DateTimeImmutable('2026-11-03T22:00:00+00:00');
        $queue->enqueue(self::EVENT_ID, 'pixel', 1, [], 'HTTP 503', $now);

        $deadAfter = null;
        for ($attempt = 2; $attempt <= 11 && $deadAfter === null; ++$attempt) {
            $now = $now->modify('+7 hours');
            $due = $queue->due($now, 10);
            self::assertCount(1, $due, "attempt $attempt is due");
            if ($queue->recordFailure($due[0], 'HTTP 503', $now)) {
                $deadAfter = $attempt;
            }
        }

        self::assertSame(FailedEventQueue::MAX_ATTEMPTS, $deadAfter);
        $row = array_values($pipeline->db->failedEvents)[0];
        self::assertSame('dead', $row['status']);
        self::assertSame('10', $row['attempts']);
        self::assertSame([], $queue->due($now->modify('+1 year'), 10));
    }

    public function testDeadRowsArePrunedAfterThirtyDays(): void
    {
        $pipeline = new PurchasePipeline();
        $queue = $pipeline->queue();
        $now = new \DateTimeImmutable('2026-11-03T22:00:00+00:00');
        $queue->addDead('a', 'pixel', 1, [], 'HTTP 400', $now->modify('-31 days'));
        $queue->addDead('b', 'pixel', 1, [], 'HTTP 400', $now->modify('-29 days'));
        $queue->enqueue('c', 'pixel', 1, [], 'HTTP 503', $now->modify('-40 days'));

        $queue->pruneDead($now);

        self::assertSame(['b', 'c'], array_column($pipeline->db->failedEvents, 'event_id'));
    }

    public function testRefreshDueAtFollowsTheTable(): void
    {
        $pipeline = new PurchasePipeline();
        $queue = $pipeline->queue();
        $now = new \DateTimeImmutable('2026-11-03T22:00:00+00:00');
        $queue->enqueue('a', 'pixel', 1, [], 'x', $now);
        $queue->enqueue('b', 'pixel', 1, [], 'x', $now->modify('-1 hour'));

        $queue->refreshDueAt();
        self::assertSame($now->modify('-1 hour +5 minutes')->getTimestamp(), $queue->dueAt());

        foreach (array_keys($pipeline->db->failedEvents) as $id) {
            $queue->delete($id);
        }
        $queue->refreshDueAt();
        self::assertNull($queue->dueAt());
    }

    public function testUnknownEndpointIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PurchasePipeline())->queue()->enqueue('a', 'track', 1, [], 'x', new \DateTimeImmutable());
    }

    public function testRetryNowQueuesParkedRowsWithAFreshAttemptCount(): void
    {
        $pipeline = new PurchasePipeline();
        $queue = $pipeline->queue();
        $then = new \DateTimeImmutable('2026-11-03T20:00:00+00:00');
        $queue->enqueue('refund-1', 'refund', 1, ['refundId' => 'refund-1'], 'HTTP 503', $then);
        $event = $queue->due($then->modify('+1 hour'), 1)[0];
        for ($i = 0; $i < 8; ++$i) {
            $queue->recordFailure($event, 'HTTP 503', $then);
            $event = $queue->due($then->modify('+1 year'), 1)[0];
        }
        $queue->markParked($event, 'secret key rejected', $then);
        self::assertSame('10', array_values($pipeline->db->failedEvents)[0]['attempts']);

        $now = new \DateTimeImmutable('2026-11-04T09:00:00+00:00');
        $queue->makeQueuedDue($now);

        $row = array_values($pipeline->db->failedEvents)[0];
        self::assertSame(['queued', '0', '2026-11-04 09:00:00'], [$row['status'], $row['attempts'], $row['next_attempt_at']]);
        $queue->recordFailure($queue->due($now, 1)[0], 'HTTP 503', $now);
        self::assertSame('queued', array_values($pipeline->db->failedEvents)[0]['status'], 'the full schedule again, not dead after one failure');
    }
}
