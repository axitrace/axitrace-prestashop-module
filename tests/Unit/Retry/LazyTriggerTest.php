<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Retry;

use Axitrace\PrestaShop\Retry\CronResponder;
use Axitrace\PrestaShop\Tests\Unit\Support\Fixtures;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHttpTransport;
use Axitrace\PrestaShop\Tests\Unit\Support\PurchasePipeline;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Purchase\PurchaseLedger;
use Axitrace\PrestaShop\Retry\LazyTrigger;
use Axitrace\PrestaShop\Retry\RetryRunner;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;
use Axitrace\PrestaShop\Tests\Unit\Support\FailingStatementDb;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeShopGroupResolver;
use PHPUnit\Framework\TestCase;

final class LazyTriggerTest extends TestCase
{
    private function pipelineWithFailedPurchase(): PurchasePipeline
    {
        $pipeline = new PurchasePipeline();
        $order = Fixtures::orders('9.2.0', 'k_paid_at_creation')[0];
        $pipeline->place($order);
        $pipeline->transport->willAnswer(FakeHttpTransport::timeout());
        $pipeline->status($order->idOrder, 2, 1);

        return $pipeline;
    }

    public function testNothingIsScheduledBeforeTheRowIsDue(): void
    {
        $pipeline = $this->pipelineWithFailedPurchase();

        self::assertFalse($pipeline->lazyTrigger()->maybeSchedule(true));
        self::assertFalse($pipeline->lazyTrigger()->maybeSchedule(false));
        self::assertSame([], $pipeline->scheduler->tasks);
    }

    public function testEmptyQueueSchedulesNothing(): void
    {
        $pipeline = new PurchasePipeline();

        self::assertFalse($pipeline->lazyTrigger()->maybeSchedule(false));
    }

    public function testDueRowsAreSentAfterTheStorefrontResponseUnderFastCgi(): void
    {
        $pipeline = $this->pipelineWithFailedPurchase();
        $pipeline->clock->now = $pipeline->clock->now->modify('+5 minutes');

        self::assertTrue($pipeline->lazyTrigger()->maybeSchedule(true));
        self::assertCount(1, $pipeline->transport->requests, 'nothing sent during the request');

        $pipeline->scheduler->runDeferred();

        self::assertCount(2, $pipeline->transport->requests);
        self::assertSame([], $pipeline->db->failedEvents);
        self::assertNotNull($pipeline->db->orders[6]['purchase_sent_at']);
        self::assertSame('0', $pipeline->store->get('AXITRACE_RETRY_LOCK'), 'lock released');
    }

    public function testStorefrontWithoutFastCgiNeverRunsTheBatchButTheBackOfficeDoes(): void
    {
        $pipeline = $this->pipelineWithFailedPurchase();
        $pipeline->clock->now = $pipeline->clock->now->modify('+5 minutes');
        $pipeline->scheduler->fastCgi = false;

        self::assertFalse($pipeline->lazyTrigger()->maybeSchedule(true));
        self::assertTrue($pipeline->lazyTrigger()->maybeSchedule(false));
        $pipeline->scheduler->runDeferred();

        self::assertCount(2, $pipeline->transport->requests);
    }

    public function testARunningBatchElsewhereMeansNoSecondRun(): void
    {
        $pipeline = $this->pipelineWithFailedPurchase();
        $pipeline->clock->now = $pipeline->clock->now->modify('+5 minutes');
        self::assertTrue($pipeline->lock()->acquire($pipeline->clock->now));

        self::assertNull($pipeline->lazyTrigger()->runLocked(20, 1.5));
        self::assertCount(1, $pipeline->transport->requests);
    }

    public function testCronWrongTokenIsForbiddenRightTokenRunsTheBatch(): void
    {
        $pipeline = $this->pipelineWithFailedPurchase();
        $pipeline->clock->now = $pipeline->clock->now->modify('+5 minutes');
        $cron = new CronResponder($pipeline->lazyTrigger());

        self::assertSame(['status' => 403, 'body' => "Forbidden\n"], $cron->respond(false));
        self::assertCount(1, $pipeline->transport->requests);

        self::assertSame(['status' => 200, 'body' => "processed=1 delivered=1 failed=0 dead=0 parked=0\n"], $cron->respond(true));
        self::assertSame(['status' => 200, 'body' => "processed=0 delivered=0 failed=0 dead=0 parked=0\n"], $cron->respond(true));
    }

    public function testCronWhileLockedSaysSo(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->lock()->acquire($pipeline->clock->now);

        self::assertSame(['status' => 200, 'body' => "processed=0 locked=1\n"], (new CronResponder($pipeline->lazyTrigger()))->respond(true));
    }

    public function testAFailedRunIsRecordedForTheBackOfficeNotOnlyLogged(): void
    {
        $pipeline = new PurchasePipeline();
        $db = new FailingStatementDb($pipeline->db, 'ORDER BY `next_attempt_at`, `id` LIMIT');
        $runner = new RetryRunner(
            $pipeline->queue($db),
            $pipeline->sender(),
            $pipeline->refundSender(),
            new PurchaseLedger($pipeline->db),
            $pipeline->refundLedger(),
            new CartSnapshotRepository($pipeline->db),
            new FakeShopGroupResolver(),
            $pipeline->failures(),
            $pipeline->logger,
            $pipeline->clock,
        );
        $trigger = new LazyTrigger($pipeline->queue(), $pipeline->lock(), $runner, $pipeline->scheduler, $pipeline->failures(), $pipeline->logger, $pipeline->clock);

        $report = $trigger->runLocked(RetryRunner::CRON_BATCH, 1.0);

        self::assertSame(0, $report?->processed);
        $failure = $pipeline->failures()->allWithGlobal(ShopScope::shop(1, 2))[0] ?? null;
        self::assertSame(LazyTrigger::FAILURE_CODE, $failure['code'] ?? null);
        self::assertStringContainsString('the retry run failed (RuntimeException: Database statement failed: Deadlock', $failure['message']);
        self::assertTrue($pipeline->lock()->acquire($pipeline->clock->now()), 'the lock was released');
    }
}
