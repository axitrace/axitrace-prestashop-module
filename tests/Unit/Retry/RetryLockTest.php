<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Retry;

use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Retry\RetryLock;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryModuleDb;
use Axitrace\PrestaShop\Tests\Unit\Support\RecordingDb;
use PHPUnit\Framework\TestCase;

final class RetryLockTest extends TestCase
{
    private InMemoryConfigurationStore $store;
    private InMemoryModuleDb $db;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigurationStore();
        $this->store->setGlobal(ModuleConfig::RETRY_LOCK, '0');
        $this->db = new InMemoryModuleDb();
        $this->db->configuration = $this->store;
        $this->now = new \DateTimeImmutable('2026-11-03T22:00:00+00:00');
    }

    private function lock(): RetryLock
    {
        return new RetryLock($this->db, $this->store);
    }

    public function testOnlyOneOfTwoConcurrentRunsGetsTheLock(): void
    {
        self::assertTrue($this->lock()->acquire($this->now));
        self::assertFalse($this->lock()->acquire($this->now));
        self::assertFalse($this->lock()->acquire($this->now->modify('+119 seconds')));
    }

    public function testReleasedLockCanBeTakenAgain(): void
    {
        $lock = $this->lock();
        self::assertTrue($lock->acquire($this->now));
        $lock->release();

        self::assertTrue($this->lock()->acquire($this->now->modify('+1 second')));
    }

    public function testALockOlderThanTwoMinutesIsTakenOver(): void
    {
        self::assertTrue($this->lock()->acquire($this->now));

        self::assertTrue($this->lock()->acquire($this->now->modify('+121 seconds')));
        self::assertSame((string) $this->now->modify('+121 seconds')->getTimestamp(), $this->store->get(ModuleConfig::RETRY_LOCK));
    }

    public function testMissingRowIsCreatedOnce(): void
    {
        unset($this->store->values['0:0:' . ModuleConfig::RETRY_LOCK]);

        self::assertTrue($this->lock()->acquire($this->now));
        self::assertFalse($this->lock()->acquire($this->now));
    }

    public function testTheLockIsOneConditionalUpdateOfTheGlobalRow(): void
    {
        $db = new RecordingDb();
        (new RetryLock($db, $this->store))->acquire($this->now);

        self::assertSame(
            sprintf("UPDATE `ps_configuration` SET `value` = '%d' WHERE `name` = 'AXITRACE_RETRY_LOCK' AND `id_shop` IS NULL AND `id_shop_group` IS NULL AND (`value` IS NULL OR CAST(`value` AS UNSIGNED) < %d)", $this->now->getTimestamp(), $this->now->getTimestamp() - 120),
            $db->executed[0],
        );
    }
}
