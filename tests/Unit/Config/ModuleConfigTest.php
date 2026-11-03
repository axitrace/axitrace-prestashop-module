<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Config;

use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Consent\ConsentMode;
use Axitrace\PrestaShop\Normalizer\ConversionValueBasis;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use PHPUnit\Framework\TestCase;

final class ModuleConfigTest extends TestCase
{
    private const PK = 'pk_live_0123456789abcdef0123456789abcdef';

    public function testEnabledNeedsTheSwitchAndAValidPublicKey(): void
    {
        $store = new InMemoryConfigurationStore();
        $config = new ModuleConfig($store);
        $scope = ShopScope::context();

        $store->setGlobal(ModuleConfig::ENABLED, '1');
        self::assertFalse($config->isEnabled($scope));

        $store->setGlobal(ModuleConfig::PUBLIC_KEY, 'pk_test_0123456789abcdef0123456789abcdef');
        self::assertFalse($config->isEnabled($scope), 'only pk_live_ keys are issued');

        $store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK);
        self::assertTrue($config->isEnabled($scope));

        $store->setGlobal(ModuleConfig::ENABLED, '0');
        self::assertFalse($config->isEnabled($scope));
    }

    public function testDefaultsWhenNothingIsStored(): void
    {
        $config = new ModuleConfig(new InMemoryConfigurationStore());
        $scope = ShopScope::context();

        self::assertSame('stat.axitrace.com', $config->trackingDomain($scope));
        self::assertSame(ConversionValueBasis::GrossTotal, $config->valueBasis($scope));
        self::assertSame(ConsentMode::Off, $config->consentMode($scope));
        self::assertSame('', $config->consentCookie($scope));
        self::assertSame([], $config->codModules($scope), 'the default is written by the installer');
        self::assertSame([], $config->excludedGroupIds($scope));
        self::assertFalse($config->isDebug($scope));
    }

    public function testValuesAreCastAndRevalidated(): void
    {
        $store = new InMemoryConfigurationStore();
        $config = new ModuleConfig($store);
        $scope = ShopScope::context();

        $store->setGlobal(ModuleConfig::VALUE_BASIS, 'NET_TOTAL');
        $store->setGlobal(ModuleConfig::CONSENT_MODE, 'nonsense');
        $store->setGlobal(ModuleConfig::CONSENT_COOKIE, 'bad cookie;');
        $store->setGlobal(ModuleConfig::COD_MODULES, ' ps_cashondelivery, codfee ,bad name,codfee');
        $store->setGlobal(ModuleConfig::EXCLUDED_GROUPS, '3,x,0, 4 ,3');
        $store->setGlobal(ModuleConfig::DEBUG, '1');

        self::assertSame(ConversionValueBasis::NetTotal, $config->valueBasis($scope));
        self::assertSame(ConsentMode::Off, $config->consentMode($scope));
        self::assertSame('', $config->consentCookie($scope));
        self::assertSame(['ps_cashondelivery', 'codfee'], $config->codModules($scope));
        self::assertSame([3, 4], $config->excludedGroupIds($scope));
        self::assertTrue($config->isDebug($scope));
    }

    public function testEmptyCodListIsKeptEmpty(): void
    {
        $store = new InMemoryConfigurationStore();
        $store->setGlobal(ModuleConfig::COD_MODULES, '');

        self::assertSame([], (new ModuleConfig($store))->codModules(ShopScope::context()));
    }

    public function testShopValuesOverrideTheGlobalValue(): void
    {
        $store = new InMemoryConfigurationStore();
        $store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK);
        $store->set(ModuleConfig::PUBLIC_KEY, 'pk_live_' . str_repeat('b', 32), 1, 2);
        $config = new ModuleConfig($store);

        self::assertSame(self::PK, $config->publicKey(ShopScope::shop(1, 1)));
        self::assertSame('pk_live_' . str_repeat('b', 32), $config->publicKey(ShopScope::shop(1, 2)));
    }

    public function testEveryOwnedKeyCarriesThePrefix(): void
    {
        foreach (ModuleConfig::ALL_KEYS as $key) {
            self::assertStringStartsWith(ModuleConfig::PREFIX, $key);
        }
        self::assertCount(14, array_unique(ModuleConfig::ALL_KEYS));
    }
}
