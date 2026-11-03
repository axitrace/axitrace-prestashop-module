<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Retry;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;
use Axitrace\PrestaShop\Adapter\DbGatewayInterface;
use Axitrace\PrestaShop\Config\ModuleConfig;

/**
 * One retry run at a time across all PHP processes: an atomic UPDATE of the global
 * AXITRACE_RETRY_LOCK configuration row (value = Unix time the lock was taken). The UPDATE only
 * matches when the lock is free or older than {@see TTL_SECONDS} (a run that died without releasing
 * it), so exactly one of several concurrent requests changes the row and wins.
 *
 * The row is written directly, not through Configuration::updateValue(), which is a read-then-write
 * and not atomic.
 */
final class RetryLock
{
    public const TTL_SECONDS = 120;

    public function __construct(
        private readonly DbGatewayInterface $db,
        private readonly ConfigurationStoreInterface $store,
    ) {
    }

    public function acquire(\DateTimeImmutable $now): bool
    {
        if ($this->tryUpdate($now)) {
            return true;
        }

        // The row is created at install; a shop installed before it existed gets it here.
        if ($this->store->get(ModuleConfig::RETRY_LOCK) === null) {
            $this->store->setGlobal(ModuleConfig::RETRY_LOCK, '0');

            return $this->tryUpdate($now);
        }

        return false;
    }

    public function release(): void
    {
        $this->db->update(sprintf(
            "UPDATE `%sconfiguration` SET `value` = '0' WHERE `name` = '%s' AND `id_shop` IS NULL AND `id_shop_group` IS NULL",
            $this->db->prefix(),
            ModuleConfig::RETRY_LOCK,
        ));
    }

    private function tryUpdate(\DateTimeImmutable $now): bool
    {
        $timestamp = $now->getTimestamp();

        return $this->db->update(sprintf(
            "UPDATE `%sconfiguration` SET `value` = '%d' WHERE `name` = '%s' AND `id_shop` IS NULL AND `id_shop_group` IS NULL"
            . ' AND (`value` IS NULL OR CAST(`value` AS UNSIGNED) < %d)',
            $this->db->prefix(),
            $timestamp,
            ModuleConfig::RETRY_LOCK,
            $timestamp - self::TTL_SECONDS,
        )) === 1;
    }
}
