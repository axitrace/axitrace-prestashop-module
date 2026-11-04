<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Config;

use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use Axitrace\PrestaShop\Tests\Unit\Support\MutableClock;
use Axitrace\PrestaShop\Tests\Unit\Support\SpyLogger;
use PHPUnit\Framework\TestCase;

final class FailureRecorderTest extends TestCase
{
    private InMemoryConfigurationStore $store;
    private SpyLogger $logger;
    private MutableClock $clock;
    private FailureRecorder $failures;
    private ShopScope $scope;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigurationStore();
        $this->logger = new SpyLogger();
        $this->clock = new MutableClock();
        $this->failures = new FailureRecorder($this->store, $this->logger, $this->clock);
        $this->scope = ShopScope::shop(1, 1);
    }

    public function testClearingOneCodeKeepsTheOthers(): void
    {
        $this->failures->record('payload_rejected', 'AxiTrace refused the purchase of order 5.', $this->scope);
        $this->clock->now = $this->clock->now->modify('+1 minute');
        $this->failures->record('delivery_failed', 'The purchase of order 6 could not be delivered.', $this->scope);

        $this->failures->clear('delivery_failed', $this->scope);

        self::assertSame(['payload_rejected'], array_column($this->failures->all($this->scope), 'code'));
        self::assertSame('payload_rejected', $this->failures->last($this->scope)['code'] ?? null);
    }

    public function testARepeatedFailureUpdatesItsEntryAtMostEveryTenMinutes(): void
    {
        $this->failures->record('a', 'first a', $this->scope);
        $this->clock->now = $this->clock->now->modify('+1 minute');
        $this->failures->record('b', 'first b', $this->scope);
        $this->clock->now = $this->clock->now->modify('+1 minute');
        $this->failures->record('a', 'second a, within ten minutes', $this->scope);

        $all = $this->failures->all($this->scope);
        self::assertSame(['b', 'a'], array_column($all, 'code'));
        self::assertSame(['first a', '2026-11-03T12:00:00+00:00', 1], [$all[1]['message'], $all[1]['last_at'], $all[1]['count']], 'not written within ten minutes');
        self::assertCount(3, $this->logger->errors, 'every occurrence is still logged');

        $this->clock->now = $this->clock->now->modify('+10 minutes');
        $this->failures->record('a', 'third a', $this->scope);

        $a = $this->failures->last($this->scope);
        self::assertSame(['a', 'third a', '2026-11-03T12:00:00+00:00', '2026-11-03T12:12:00+00:00', 2], [$a['code'] ?? null, $a['message'] ?? null, $a['first_at'] ?? null, $a['last_at'] ?? null, $a['count'] ?? null]);
    }

    public function testQueueFailuresAreGlobalAndMergedIntoEveryShopView(): void
    {
        $this->failures->recordGlobal('retry_run', 'the retry run failed');
        $this->clock->now = $this->clock->now->modify('+1 minute');
        $this->failures->record('delivery_failed', 'shop 1 delivery', ShopScope::shop(1, 1));

        self::assertSame(['delivery_failed', 'retry_run'], array_column($this->failures->allWithGlobal(ShopScope::shop(1, 1)), 'code'));
        self::assertSame(['retry_run'], array_column($this->failures->allWithGlobal(ShopScope::shop(1, 2)), 'code'));
        self::assertSame([], $this->failures->all(ShopScope::shop(1, 2)), 'not stored in a shop');

        $this->failures->clearAll(ShopScope::shop(1, 2));
        self::assertSame(['delivery_failed'], array_column($this->failures->allWithGlobal(ShopScope::shop(1, 1)), 'code'), 'dismiss clears the queue failures, not another shop');
    }

    public function testAMessageWithAngleBracketsSurvivesTheConfigurationStore(): void
    {
        // Configuration::updateValue() strips tags (the store double does too): the JSON escapes them.
        $this->failures->record('payload_rejected', 'AxiTrace refused the purchase of "Ring <5mm>" & co.', $this->scope);

        self::assertSame('AxiTrace refused the purchase of "Ring <5mm>" & co.', $this->failures->last($this->scope)['message'] ?? null);
    }

    public function testClearAllForgetsEverything(): void
    {
        $this->failures->record('a', 'x', $this->scope);
        $this->failures->record('b', 'y', $this->scope);

        $this->failures->clearAll($this->scope);

        self::assertSame([], $this->failures->all($this->scope));
        self::assertSame('', $this->store->get(ModuleConfig::LAST_FAILURE, 1, 1));
    }

    public function testTheSingleEntryOfModule010IsRead(): void
    {
        $this->store->set(ModuleConfig::LAST_FAILURE, '{"at":"2026-11-01T10:00:00+00:00","code":"order_status","message":"old"}', 1, 1);

        self::assertSame([['code' => 'order_status', 'message' => 'old', 'first_at' => '2026-11-01T10:00:00+00:00', 'last_at' => '2026-11-01T10:00:00+00:00', 'count' => 1]], $this->failures->all($this->scope));

        $this->failures->record('delivery_failed', 'new', $this->scope);
        self::assertSame(['delivery_failed', 'order_status'], array_column($this->failures->all($this->scope), 'code'));
    }

    public function testAnUnreadableValueIsLoggedNotSilentlyIgnored(): void
    {
        $this->store->set(ModuleConfig::LAST_FAILURE, '{"broken', 1, 1);

        self::assertSame([], $this->failures->all($this->scope));
        self::assertStringContainsString('AXITRACE_LAST_FAILURE is not valid JSON', $this->logger->errors[0] ?? '');
    }
}
