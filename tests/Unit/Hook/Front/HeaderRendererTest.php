<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Hook\Front;

use Axitrace\PrestaShop\Config\EndpointResolver;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Exclusion\CustomerGroupExclusion;
use Axitrace\PrestaShop\Hook\Front\HeaderRenderer;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeEnvironment;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHostResolver;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use PHPUnit\Framework\TestCase;

final class HeaderRendererTest extends TestCase
{
    private const PK = 'pk_live_0123456789abcdef0123456789abcdef';
    private const STATUS_URL = 'https://shop.example/module/axitrace/status';

    private InMemoryConfigurationStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigurationStore();
        foreach (ModuleConfig::installDefaults() as $key => $value) {
            $this->store->setGlobal($key, $value);
        }
        $this->store->setGlobal(ModuleConfig::ENABLED, '1');
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK);
    }

    public function testConfigKeys(): void
    {
        $config = $this->renderer()->config(ShopScope::context(), 'product', '0.1.0', self::STATUS_URL);

        self::assertSame([
            'publicKey' => self::PK,
            'sdkUrl' => 'https://stat.axitrace.com/axitrack.js',
            'apiUrl' => 'https://stat.axitrace.com',
            'consentMode' => 'off',
            'consentCookie' => '',
            'pageType' => 'product',
            'moduleVersion' => '0.1.0',
            'statusUrl' => '',
            'debug' => false,
        ], $config);
    }

    public function testNothingWhenDisabledOrWithoutKey(): void
    {
        $this->store->setGlobal(ModuleConfig::ENABLED, '0');
        self::assertNull($this->renderer()->config(ShopScope::context(), 'index', '0.1.0', self::STATUS_URL));
        self::assertSame('', $this->renderer()->render(ShopScope::context(), 'index', '0.1.0', self::STATUS_URL));

        $this->store->setGlobal(ModuleConfig::ENABLED, '1');
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, '');
        $withoutKey = $this->renderer();
        self::assertSame('', $withoutKey->render(ShopScope::context(), 'index', '0.1.0', self::STATUS_URL));
    }

    public function testBrowserUrlsComeFromTheSdkBaseNeverTheServerApiBase(): void
    {
        $environment = new FakeEnvironment(true, [
            EndpointResolver::ENV_API_OVERRIDE => 'http://velitrack-ingestion-api:8080',
            EndpointResolver::ENV_SDK_OVERRIDE => 'http://localhost:8080/',
        ]);
        $config = $this->renderer($environment)->config(ShopScope::context(), 'index', '0.1.0', self::STATUS_URL);

        self::assertNotNull($config);
        self::assertSame('http://localhost:8080/axitrack.js', $config['sdkUrl']);
        self::assertSame('http://localhost:8080', $config['apiUrl']);
        self::assertStringNotContainsString('velitrack-ingestion-api', (string) json_encode($config));
    }

    public function testCustomTrackingDomain(): void
    {
        $this->store->setGlobal(ModuleConfig::TRACKING_DOMAIN, 'pixel.shop.example');
        $config = $this->renderer(null, new FakeHostResolver(['pixel.shop.example' => ['93.184.216.34']]))
            ->config(ShopScope::context(), 'index', '0.1.0', self::STATUS_URL);

        self::assertNotNull($config);
        self::assertSame('https://pixel.shop.example/axitrack.js', $config['sdkUrl']);
    }

    public function testStatusUrlOnlyWhenAGroupIsExcluded(): void
    {
        $this->store->setGlobal(ModuleConfig::EXCLUDED_GROUPS, '4');
        $config = $this->renderer()->config(ShopScope::context(), 'index', '0.1.0', self::STATUS_URL);

        self::assertNotNull($config);
        self::assertSame(self::STATUS_URL, $config['statusUrl']);
    }

    public function testConsentPolicyAndDebug(): void
    {
        $this->store->setGlobal(ModuleConfig::CONSENT_MODE, 'all');
        $this->store->setGlobal(ModuleConfig::CONSENT_COOKIE, 'cookie_consent');
        $this->store->setGlobal(ModuleConfig::DEBUG, '1');
        $config = $this->renderer()->config(ShopScope::context(), 'index', '0.1.0', self::STATUS_URL);

        self::assertNotNull($config);
        self::assertSame('all', $config['consentMode']);
        self::assertSame('cookie_consent', $config['consentCookie']);
        self::assertTrue($config['debug']);
    }

    public function testJsonCannotCloseTheScriptElement(): void
    {
        $json = $this->renderer()->render(ShopScope::context(), '</script><b>&\'"', '0.1.0', self::STATUS_URL);
        self::assertStringNotContainsString('<', $json);

        // The page type is sanitised; the escaping itself is proven on a hostile version string.
        $json = $this->renderer()->render(ShopScope::context(), 'index', "</script><img src=x onerror='a'>&\"", self::STATUS_URL);
        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertStringNotContainsString('&', $json);
        self::assertStringNotContainsString("'", $json);
        self::assertStringContainsString('\\u003C/script\\u003E', $json);
        self::assertSame("</script><img src=x onerror='a'>&\"", json_decode($json, true)['moduleVersion']);
    }

    /**
     * The header is served from page caches to every visitor: for a logged-in customer it must hold
     * exactly the shop-level keys and nothing that identifies the visitor.
     */
    public function testNoCustomerFieldForALoggedInCustomer(): void
    {
        $_COOKIE['vt_vid'] = 'visitor';
        try {
            $config = $this->renderer()->config(ShopScope::context(), 'my-account', '0.1.0', self::STATUS_URL);
        } finally {
            unset($_COOKIE['vt_vid']);
        }

        self::assertNotNull($config);
        self::assertSame(
            ['publicKey', 'sdkUrl', 'apiUrl', 'consentMode', 'consentCookie', 'pageType', 'moduleVersion', 'statusUrl', 'debug'],
            array_keys($config),
        );
        $json = (string) json_encode($config);
        foreach (['email', 'customer', 'firstname', 'lastname', 'phone', 'group', 'visitor'] as $field) {
            self::assertStringNotContainsStringIgnoringCase($field, $json);
        }
    }

    private function renderer(?FakeEnvironment $environment = null, ?FakeHostResolver $resolver = null): HeaderRenderer
    {
        $config = new ModuleConfig($this->store);

        return new HeaderRenderer(
            $config,
            new EndpointResolver($environment ?? new FakeEnvironment(), $resolver ?? new FakeHostResolver()),
            new CustomerGroupExclusion($config),
        );
    }
}
