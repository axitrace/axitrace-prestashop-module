<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Config;

use Axitrace\PrestaShop\Admin\ConfigFormHandler;
use Axitrace\PrestaShop\Config\EndpointResolver;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Consent\ConsentMode;
use Axitrace\PrestaShop\Normalizer\ConversionValueBasis;
use Axitrace\PrestaShop\Retry\RetryRunner;
use Axitrace\PrestaShop\Tests\Unit\Support\EchoTranslator;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeEnvironment;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHostResolver;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHttpTransport;
use Axitrace\PrestaShop\Tests\Unit\Support\Fixtures;
use Axitrace\PrestaShop\Tests\Unit\Support\PurchasePipeline;
use PHPUnit\Framework\TestCase;

/**
 * Two shops in one group, each with its own workspace and tracking domain (A27): every order,
 * its refunds and its retries use the settings of the shop the order was placed in, whatever the
 * shop of the request that runs them.
 */
final class MultistoreTest extends TestCase
{
    private const PK_SHOP_2 = 'pk_live_22222222222222222222222222222222';
    private const SK_SHOP_2 = 'sk_live_2222222222222222222222222222222222222222222222222222222222222222';

    private static function pipeline(): PurchasePipeline
    {
        $pipeline = new PurchasePipeline();
        $pipeline->endpoints = new EndpointResolver(new FakeEnvironment(false), new FakeHostResolver([
            'pixel.shop-two.example' => ['203.0.113.80'],
        ]));
        // All shops: the PurchasePipeline defaults (public key PK) and the shop 1 secret key.
        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        // Shop 2: its own workspace, secret key and tracking domain.
        $pipeline->store->set(ModuleConfig::PUBLIC_KEY, self::PK_SHOP_2, 1, 2);
        $pipeline->store->set(ModuleConfig::TRACKING_DOMAIN, 'pixel.shop-two.example', 1, 2);
        $pipeline->vault()->store(self::SK_SHOP_2, ShopScope::shop(1, 2));

        return $pipeline;
    }

    public function testOrderOfShop2UsesShop2KeyAndEndpoint(): void
    {
        $pipeline = self::pipeline();
        $shop1 = Fixtures::orders('8.1.7', 'k_paid_at_creation')[0];
        $shop2 = Fixtures::with($shop1, ['idOrder' => 9001, 'idShop' => 2]);
        $pipeline->place($shop1);
        $pipeline->place($shop2);

        $pipeline->status($shop1->idOrder, 2, 1);
        $pipeline->status($shop2->idOrder, 2, 1);

        [$first, $second] = $pipeline->transport->requests;
        self::assertSame('https://stat.axitrace.com/v1/plugin/pixel', $first['url']);
        self::assertSame(PurchasePipeline::PK, $first['payload']['workspace_public_key']);
        self::assertSame('Basic ' . base64_encode(PurchasePipeline::SK . ':'), $first['headers']['Authorization']);

        self::assertSame('https://pixel.shop-two.example/v1/plugin/pixel', $second['url']);
        self::assertSame(self::PK_SHOP_2, $second['payload']['workspace_public_key']);
        self::assertSame('Basic ' . base64_encode(self::SK_SHOP_2 . ':'), $second['headers']['Authorization']);
        self::assertArrayHasKey('unitCost', $second['payload']['data']['products'][0], 'shop 2 has a key: costs kept');
    }

    public function testRefundAndRetryOfAShop2OrderUseShop2Settings(): void
    {
        $pipeline = self::pipeline();
        $pipeline->shopGroups = [1 => 1, 2 => 1];
        $order = Fixtures::with(Fixtures::orders('9.2.0', 'k_paid_at_creation')[0], ['idOrder' => 9002, 'idShop' => 2]);
        $pipeline->place($order);
        $pipeline->status($order->idOrder, 2, 1);

        $pipeline->transport->willAnswer(FakeHttpTransport::timeout());
        $pipeline->status($order->idOrder, 7, 0);
        $refund = $pipeline->transport->requests[1];
        self::assertSame('https://pixel.shop-two.example/v1/refund', $refund['url']);
        self::assertSame(self::PK_SHOP_2, $refund['payload']['workspace_public_key']);
        self::assertSame('2', (string) array_values($pipeline->db->failedEvents)[0]['id_shop']);

        // The retry runs in a request of another shop (or the cron URL of the default shop).
        $pipeline->store->useContext(1, 1);
        $pipeline->clock->now = $pipeline->clock->now->modify('+10 minutes');
        $pipeline->runner()->run(RetryRunner::CRON_BATCH, 60.0);

        $retry = $pipeline->transport->requests[2];
        self::assertSame('https://pixel.shop-two.example/v1/refund', $retry['url']);
        self::assertSame('Basic ' . base64_encode(self::SK_SHOP_2 . ':'), $retry['headers']['Authorization']);
        self::assertSame([], $pipeline->db->failedEvents);
    }

    public function testShopDisabledOnItsOwnSendsNothing(): void
    {
        $pipeline = self::pipeline();
        $pipeline->store->set(ModuleConfig::ENABLED, '0', 1, 2);
        $order = Fixtures::with(Fixtures::orders('8.1.7', 'k_paid_at_creation')[0], ['idOrder' => 9003, 'idShop' => 2]);
        $pipeline->place($order);
        $pipeline->status($order->idOrder, 2, 1);

        self::assertSame([], $pipeline->transport->requests);
    }

    /**
     * The form saves into the shop context picked in the back office; a value emptied for one shop
     * stays empty for it (PrestaShop reads the stored NULL back as '', checked on 8.1.7 and 9.2.0),
     * while a shop without its own value inherits.
     */
    public function testEmptiedShopValuesDoNotInheritAndUntouchedShopsDo(): void
    {
        $pipeline = new PurchasePipeline();
        $store = $pipeline->store;
        $store->setGlobal(ModuleConfig::CONSENT_COOKIE, 'cookie_consent');
        $store->setGlobal(ModuleConfig::EXCLUDED_GROUPS, '4');

        $handler = new ConfigFormHandler($store, $pipeline->config(), $pipeline->vault(), new EchoTranslator(), $pipeline->queue(), $pipeline->clock, $pipeline->domainClaimer());
        $store->useContext(1, 2);
        $handler->save([
            ModuleConfig::ENABLED => '1',
            ModuleConfig::PUBLIC_KEY => self::PK_SHOP_2,
            ModuleConfig::SECRET_KEY => '',
            ModuleConfig::TRACKING_DOMAIN => '',
            ModuleConfig::VALUE_BASIS => ConversionValueBasis::NetTotal->value,
            ModuleConfig::CONSENT_MODE => ConsentMode::Off->value,
            ModuleConfig::CONSENT_COOKIE => '',
            ModuleConfig::COD_MODULES => '',
            ModuleConfig::EXCLUDED_GROUPS => [],
            ModuleConfig::DEBUG => '0',
        ], ShopScope::context());

        $config = $pipeline->config();
        $shop2 = ShopScope::shop(1, 2);
        self::assertSame(self::PK_SHOP_2, $config->publicKey($shop2));
        self::assertSame('', $config->consentCookie($shop2));
        self::assertSame([], $config->excludedGroupIds($shop2));
        self::assertSame([], $config->codModules($shop2));
        self::assertSame(ConversionValueBasis::NetTotal, $config->valueBasis($shop2));

        $shop3 = ShopScope::shop(1, 3);
        self::assertSame(PurchasePipeline::PK, $config->publicKey($shop3));
        self::assertSame('cookie_consent', $config->consentCookie($shop3));
        self::assertSame([4], $config->excludedGroupIds($shop3));
        self::assertSame(['ps_cashondelivery'], $config->codModules($shop3));
    }
}
