<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Hook\Front;

use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Exclusion\CustomerGroupExclusion;
use Axitrace\PrestaShop\Hook\Front\StatusResponder;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use PHPUnit\Framework\TestCase;

final class StatusResponderTest extends TestCase
{
    public function testExcludedDefaultGroupIsNotTracked(): void
    {
        $store = new InMemoryConfigurationStore();
        $store->setGlobal(ModuleConfig::ENABLED, '1');
        $store->setGlobal(ModuleConfig::PUBLIC_KEY, 'pk_live_0123456789abcdef0123456789abcdef');
        $store->setGlobal(ModuleConfig::EXCLUDED_GROUPS, '4,5');
        $config = new ModuleConfig($store);
        $responder = new StatusResponder($config, new CustomerGroupExclusion($config));
        $scope = ShopScope::context();

        self::assertSame('{"tracked":false}', $responder->body(4, $scope));
        self::assertSame('{"tracked":true}', $responder->body(3, $scope));
        self::assertSame('{"tracked":true}', $responder->body(null, $scope), 'a guest cannot be recognised');

        $store->setGlobal(ModuleConfig::ENABLED, '0');
        self::assertSame('{"tracked":false}', $responder->body(3, $scope), 'disabled module tracks nobody');
    }

    public function testResponseIsNeverCached(): void
    {
        self::assertSame('no-store, private, max-age=0', StatusResponder::HEADERS['Cache-Control']);
        self::assertStringStartsWith('application/json', StatusResponder::HEADERS['Content-Type']);
    }
}
