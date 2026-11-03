<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Install;

use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Install\ConfigInstaller;
use Axitrace\PrestaShop\Tests\Unit\Support\FixedRandom;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use PHPUnit\Framework\TestCase;

final class ConfigInstallerTest extends TestCase
{
    public function testInstallWritesDefaultsAndA32ByteCronToken(): void
    {
        $store = new InMemoryConfigurationStore();
        (new ConfigInstaller($store, new FixedRandom()))->install();

        self::assertSame('0', $store->get(ModuleConfig::ENABLED));
        self::assertSame('stat.axitrace.com', $store->get(ModuleConfig::TRACKING_DOMAIN));
        self::assertSame('gross_total', $store->get(ModuleConfig::VALUE_BASIS));
        self::assertSame('off', $store->get(ModuleConfig::CONSENT_MODE));
        self::assertSame('ps_cashondelivery', $store->get(ModuleConfig::COD_MODULES));
        self::assertSame(str_repeat('ab', 32), $store->get(ModuleConfig::CRON_TOKEN));
        self::assertSame(64, strlen((string) $store->get(ModuleConfig::CRON_TOKEN)));
    }

    public function testExistingCronTokenIsKept(): void
    {
        $store = new InMemoryConfigurationStore();
        $store->setGlobal(ModuleConfig::CRON_TOKEN, 'existing');
        (new ConfigInstaller($store, new FixedRandom()))->install();

        self::assertSame('existing', $store->get(ModuleConfig::CRON_TOKEN));
    }

    public function testUninstallDeletesEveryAxitraceKeyInEveryScope(): void
    {
        $store = new InMemoryConfigurationStore();
        $installer = new ConfigInstaller($store, new FixedRandom());
        $installer->install();
        $store->set(ModuleConfig::PUBLIC_KEY, 'pk_live_' . str_repeat('a', 32), 1, 2);
        $store->setGlobal('AXITRACE_SOMETHING_FROM_A_LATER_VERSION', 'x');
        $store->setGlobal('PS_SHOP_NAME', 'Shop');

        $installer->uninstall();

        self::assertSame(['0:0:PS_SHOP_NAME' => 'Shop'], $store->values);
    }
}
