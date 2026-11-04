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

    public function testEntriesAreNewestFirstAndAKnownCodeIsWrittenOnce(): void
    {
        $this->failures->record('a', 'first a', $this->scope);
        $this->clock->now = $this->clock->now->modify('+1 minute');
        $this->failures->record('b', 'first b', $this->scope);
        $this->clock->now = $this->clock->now->modify('+1 minute');
        $this->failures->record('a', 'second a', $this->scope);

        $all = $this->failures->all($this->scope);
        self::assertSame(['b', 'a'], array_column($all, 'code'));
        self::assertSame('first a', $all[1]['message']);
        self::assertCount(3, $this->logger->errors, 'every occurrence is still logged');
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

        self::assertSame([['at' => '2026-11-01T10:00:00+00:00', 'code' => 'order_status', 'message' => 'old']], $this->failures->all($this->scope));

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
