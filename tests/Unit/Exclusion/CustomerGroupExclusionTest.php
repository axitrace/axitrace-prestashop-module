<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Exclusion;

use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Exclusion\CustomerGroupExclusion;
use Axitrace\PrestaShop\Hook\Front\StatusResponder;
use Axitrace\PrestaShop\Hook\Order\RefundHook;
use Axitrace\PrestaShop\Tests\Unit\Support\Fixtures;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use Axitrace\PrestaShop\Tests\Unit\Support\PurchasePipeline;
use PHPUnit\Framework\TestCase;

/**
 * B2B exclusion (A40): one rule, the customer's DEFAULT group, shared by the storefront status
 * controller and the server-side order pipeline, read per shop.
 */
final class CustomerGroupExclusionTest extends TestCase
{
    private static function exclusion(InMemoryConfigurationStore $store): CustomerGroupExclusion
    {
        return new CustomerGroupExclusion(new ModuleConfig($store));
    }

    public function testDefaultGroupInTheListIsExcluded(): void
    {
        $store = new InMemoryConfigurationStore();
        $store->setGlobal(ModuleConfig::EXCLUDED_GROUPS, '4,5');
        $exclusion = self::exclusion($store);
        $scope = ShopScope::shop(1, 1);

        self::assertTrue($exclusion->isConfigured($scope));
        self::assertTrue($exclusion->excludes(4, $scope));
        self::assertTrue($exclusion->excludes(5, $scope));
        self::assertFalse($exclusion->excludes(3, $scope), 'customer group');
        self::assertFalse($exclusion->excludes(null, $scope), 'a visitor who is not logged in cannot be recognised');
        self::assertFalse($exclusion->excludes(0, $scope));
    }

    public function testNothingConfiguredExcludesNobody(): void
    {
        $exclusion = self::exclusion(new InMemoryConfigurationStore());

        self::assertFalse($exclusion->isConfigured(ShopScope::shop(1, 1)));
        self::assertFalse($exclusion->excludes(4, ShopScope::shop(1, 1)));
    }

    public function testCorruptedValueCanOnlyExcludeLess(): void
    {
        $store = new InMemoryConfigurationStore();
        $store->setGlobal(ModuleConfig::EXCLUDED_GROUPS, 'all, 4, -1, 0, 4x, *');

        self::assertSame([4], (new ModuleConfig($store))->excludedGroupIds(ShopScope::shop(1, 1)));
        self::assertFalse(self::exclusion($store)->excludes(3, ShopScope::shop(1, 1)));
    }

    public function testEachShopHasItsOwnList(): void
    {
        $store = new InMemoryConfigurationStore();
        $store->setGlobal(ModuleConfig::EXCLUDED_GROUPS, '4');
        $store->set(ModuleConfig::EXCLUDED_GROUPS, '', 1, 2);
        $store->set(ModuleConfig::EXCLUDED_GROUPS, '7', 1, 3);
        $exclusion = self::exclusion($store);

        self::assertTrue($exclusion->excludes(4, ShopScope::shop(1, 1)), 'inherits the all-shops list');
        self::assertFalse($exclusion->excludes(4, ShopScope::shop(1, 2)), 'emptied for shop 2');
        self::assertFalse($exclusion->excludes(4, ShopScope::shop(1, 3)));
        self::assertTrue($exclusion->excludes(7, ShopScope::shop(1, 3)));
    }

    public function testStatusControllerAndServerShareTheRule(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->store->setGlobal(ModuleConfig::EXCLUDED_GROUPS, '3');
        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        $status = new StatusResponder($pipeline->config(), new CustomerGroupExclusion($pipeline->config()));

        // Storefront: the logged-in customer of group 3 gets {"tracked": false}.
        self::assertSame('{"tracked":false}', $status->body(3, ShopScope::shop(1, 1)));
        self::assertSame('{"tracked":true}', $status->body(null, ShopScope::shop(1, 1)));

        // Server: the order of a group 3 customer (Fixtures customer default group 3) is never
        // sent, so neither is its cancellation.
        $order = Fixtures::orders('8.1.7', 'a_cod_order')[0];
        self::assertSame(3, $order->customerDefaultGroupId);
        $pipeline->place($order);
        $pipeline->status($order->idOrder, 13, 0);
        $pipeline->status($order->idOrder, 6, 0);
        $pipeline->refundHook()->onProductCancel($order->idOrder, $order->lines[1]->idOrderDetail, 1, RefundHook::ACTION_CANCEL_LINE);

        self::assertSame([], $pipeline->transport->requests);
        self::assertSame('void', $pipeline->db->orders[$order->idOrder]['trigger']);
        self::assertSame([], $pipeline->db->refunds);
    }
}
