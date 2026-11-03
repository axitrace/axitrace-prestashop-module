<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;
use Axitrace\PrestaShop\Adapter\SecureRandomInterface;
use Axitrace\PrestaShop\Config\ModuleConfig;

/**
 * Writes the default settings and the cron token at install and removes every AXITRACE_* key at
 * uninstall.
 */
final class ConfigInstaller
{
    public function __construct(
        private readonly ConfigurationStoreInterface $store,
        private readonly SecureRandomInterface $random,
    ) {
    }

    public function install(): void
    {
        foreach (ModuleConfig::installDefaults() as $key => $value) {
            $this->store->setGlobal($key, $value);
        }

        // Never replace an existing token: the merchant may already call the cron URL with it.
        if (trim((string) $this->store->get(ModuleConfig::CRON_TOKEN)) === '') {
            $this->store->setGlobal(ModuleConfig::CRON_TOKEN, $this->random->hex(32));
        }
    }

    public function uninstall(): void
    {
        $names = array_unique(array_merge(
            ModuleConfig::ALL_KEYS,
            $this->store->namesWithPrefix(ModuleConfig::PREFIX),
        ));

        foreach ($names as $name) {
            $this->store->delete($name);
        }
    }
}
