<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Admin;

use Axitrace\PrestaShop\Adapter\HttpResponse;
use Axitrace\PrestaShop\Admin\ConfigFormHandler;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\SecretKeyState;
use Axitrace\PrestaShop\Config\SecretKeyVault;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Domain\ShopHosts;
use Axitrace\PrestaShop\Domain\StoreDomainClaimer;
use Axitrace\PrestaShop\Domain\StoreDomainStatus;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeEnvironment;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHttpTransport;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeStoreHostsReader;
use Axitrace\PrestaShop\Tests\Unit\Support\EchoTranslator;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeCipher;
use Axitrace\PrestaShop\Tests\Unit\Support\FixedClock;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use Axitrace\PrestaShop\Tests\Unit\Support\SpyLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Axitrace\PrestaShop\Retry\FailedEventQueue;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryModuleDb;
use PHPUnit\Framework\TestCase;

final class ConfigFormHandlerTest extends TestCase
{
    private const PK = 'pk_live_0123456789abcdef0123456789abcdef';
    private const SK = 'sk_live_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const GROUPS = [1, 2, 3, 4];

    private InMemoryConfigurationStore $store;
    private SecretKeyVault $vault;
    private EchoTranslator $translator;
    private ConfigFormHandler $handler;

    private InMemoryModuleDb $db;

    private FakeHttpTransport $transport;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigurationStore();
        $this->translator = new EchoTranslator();
        $this->vault = new SecretKeyVault(
            $this->store,
            new FakeCipher(),
            new FailureRecorder($this->store, new SpyLogger(), new FixedClock()),
        );
        $this->db = new InMemoryModuleDb();
        $this->transport = new FakeHttpTransport();
        $claimer = new StoreDomainClaimer(
            new FakeStoreHostsReader(new ShopHosts(0, 0, ['shop.example'])),
            new ModuleConfig($this->store),
            $this->vault,
            $this->store,
            $this->transport,
            new FakeEnvironment(),
            new FailureRecorder($this->store, new SpyLogger(), new FixedClock()),
            new FixedClock(),
            '1.1.0',
        );
        $this->handler = new ConfigFormHandler($this->store, new ModuleConfig($this->store), $this->vault, $this->translator, new FailedEventQueue($this->db, $this->store), new FixedClock(), $claimer);
    }

    public function testSavingClaimsTheStoreDomainWithTheSavedKeys(): void
    {
        $this->transport->willAnswer(new HttpResponse(200, [], '{"success":true,"hosts":[{"host":"shop.example","status":"verified","reason":null,"browser_events_rejected":false}]}'));

        $this->handler->save(self::input(), ShopScope::context());

        self::assertCount(1, $this->transport->requests);
        self::assertSame(['public_key' => self::PK, 'platform' => 'prestashop', 'hosts' => ['shop.example']], $this->transport->requests[0]['payload']);
        self::assertSame('Basic ' . base64_encode(self::SK . ':'), $this->transport->requests[0]['headers']['Authorization'], 'the secret key saved in the same submit');
        self::assertSame('verified', StoreDomainStatus::fromJson($this->store->get(ModuleConfig::DOMAIN_STATUS, 0, 0))?->hosts[0]['status']);
    }

    public function testSavingASecretKeyRequeuesTheParkedRefunds(): void
    {
        $queue = new FailedEventQueue($this->db, $this->store);
        $parkedAt = new \DateTimeImmutable('2026-10-01T10:00:00+00:00');
        $queue->park('prestashop:5:slip:1', FailedEventQueue::ENDPOINT_REFUND, 1, ['refundId' => 'prestashop:5:slip:1'], 'secret key rejected', $parkedAt);
        $queue->enqueue('other', FailedEventQueue::ENDPOINT_PIXEL, 1, ['eventSalt' => 'other'], 'HTTP 503', (new FixedClock())->now());

        $this->handler->save(self::input(), ShopScope::context());

        $rows = array_values($this->db->failedEvents);
        self::assertSame(['queued', '0', FailedEventQueue::utc((new FixedClock())->now())], [$rows[0]['status'], $rows[0]['attempts'], $rows[0]['next_attempt_at']], 'due now with a fresh attempt count');
        self::assertSame('1', $rows[1]['attempts'], 'a queued row keeps its schedule');
        self::assertSame((new FixedClock())->now()->getTimestamp(), $queue->dueAt(), 'due now: the lazy trigger runs it at the end of this request');
    }

    public function testValidSubmissionPassesAndIsSaved(): void
    {
        $input = self::input();

        self::assertSame([], $this->handler->validate($input, self::GROUPS));
        $this->handler->save($input, ShopScope::context());

        self::assertSame('1', $this->store->get(ModuleConfig::ENABLED));
        self::assertSame(self::PK, $this->store->get(ModuleConfig::PUBLIC_KEY));
        self::assertSame('pixel.shop.example', $this->store->get(ModuleConfig::TRACKING_DOMAIN));
        self::assertSame('net_excl_shipping', $this->store->get(ModuleConfig::VALUE_BASIS));
        self::assertSame('browser', $this->store->get(ModuleConfig::CONSENT_MODE));
        self::assertSame('cookie_consent', $this->store->get(ModuleConfig::CONSENT_COOKIE));
        self::assertSame('ps_cashondelivery,codfee', $this->store->get(ModuleConfig::COD_MODULES));
        self::assertSame('3,4', $this->store->get(ModuleConfig::EXCLUDED_GROUPS));
        self::assertSame('0', $this->store->get(ModuleConfig::DEBUG));
        self::assertSame(self::SK, $this->vault->read(ShopScope::context())->key);
        self::assertNotSame(self::SK, $this->store->get(ModuleConfig::SECRET_KEY));
    }

    public function testEmptySecretKeyKeepsTheSavedKey(): void
    {
        $this->handler->save(self::input(), ShopScope::context());
        $this->handler->save(self::input([ModuleConfig::SECRET_KEY => '']), ShopScope::context());

        self::assertSame(self::SK, $this->vault->read(ShopScope::context())->key);
    }

    public function testClearCheckboxRemovesTheKeyEvenWhenAValueIsTyped(): void
    {
        $this->handler->save(self::input(), ShopScope::context());
        $input = self::input([ConfigFormHandler::FIELD_SECRET_CLEAR_INPUT => '1', ModuleConfig::SECRET_KEY => 'typo']);

        self::assertSame([], $this->handler->validate($input, self::GROUPS));
        $this->handler->save($input, ShopScope::context());

        self::assertSame(SecretKeyState::Absent, $this->vault->read(ShopScope::context())->state);
    }

    public function testEmptyTrackingDomainIsSavedAsTheDefault(): void
    {
        $this->handler->save(self::input([ModuleConfig::TRACKING_DOMAIN => '']), ShopScope::context());

        self::assertSame('stat.axitrace.com', $this->store->get(ModuleConfig::TRACKING_DOMAIN));
    }

    public function testSavingIntoAShopContextLeavesOtherShopsAlone(): void
    {
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, 'pk_live_' . str_repeat('c', 32));
        $this->handler->save(self::input(), ShopScope::shop(1, 2));

        self::assertSame(self::PK, $this->store->get(ModuleConfig::PUBLIC_KEY, 1, 2));
        self::assertSame('pk_live_' . str_repeat('c', 32), $this->store->get(ModuleConfig::PUBLIC_KEY, 1, 1));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'enabled without public key' => [[ModuleConfig::PUBLIC_KEY => ''], 'Enter the public key to enable tracking.'];
        yield 'pk_test key' => [[ModuleConfig::PUBLIC_KEY => 'pk_test_0123456789abcdef0123456789abcdef'], 'The public key must be pk_live_'];
        yield 'uppercase hex' => [[ModuleConfig::PUBLIC_KEY => 'pk_live_0123456789ABCDEF0123456789abcdef'], 'The public key must be pk_live_'];
        yield 'secret key in public field' => [[ModuleConfig::PUBLIC_KEY => self::SK], 'The public key must be pk_live_'];
        yield 'short secret key' => [[ModuleConfig::SECRET_KEY => 'sk_live_abc'], 'The secret key must be sk_live_'];
        yield 'public key in secret field' => [[ModuleConfig::SECRET_KEY => self::PK], 'The secret key must be sk_live_'];
        yield 'domain with scheme' => [[ModuleConfig::TRACKING_DOMAIN => 'https://pixel.shop.example'], 'host name only'];
        yield 'domain with path' => [[ModuleConfig::TRACKING_DOMAIN => 'pixel.shop.example/x'], 'host name only'];
        yield 'domain with port' => [[ModuleConfig::TRACKING_DOMAIN => 'pixel.shop.example:443'], 'host name only'];
        yield 'domain is an IP' => [[ModuleConfig::TRACKING_DOMAIN => '93.184.216.34'], 'not an IP address'];
        yield 'domain malformed' => [[ModuleConfig::TRACKING_DOMAIN => 'pixel_shop'], 'not a valid host name'];
        yield 'unknown value basis' => [[ModuleConfig::VALUE_BASIS => 'margin'], 'Choose a conversion value'];
        yield 'unknown consent mode' => [[ModuleConfig::CONSENT_MODE => 'maybe'], 'Choose a cookie consent mode'];
        yield 'unsafe cookie name' => [[ModuleConfig::CONSENT_COOKIE => 'a b;'], 'consent cookie name'];
        yield 'bad cod module name' => [[ModuleConfig::COD_MODULES => 'ps_cashondelivery, cod fee'], 'technical module names'];
        yield 'unknown group' => [[ModuleConfig::EXCLUDED_GROUPS => ['3', '99']], 'Select customer groups'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidInput')]
    public function testInvalidInputIsRefusedWithATranslatedMessage(array $override, string $expected): void
    {
        $errors = $this->handler->validate(self::input($override), self::GROUPS);

        self::assertCount(1, $errors);
        self::assertStringContainsString($expected, $errors[0]);
        self::assertSame(['Modules.Axitrace.Admin'], array_values(array_unique($this->translator->domains)));
    }

    public function testDisabledTrackingWithoutKeysIsValid(): void
    {
        $input = self::input([ModuleConfig::ENABLED => '0', ModuleConfig::PUBLIC_KEY => '', ModuleConfig::SECRET_KEY => '']);

        self::assertSame([], $this->handler->validate($input, self::GROUPS));
    }

    public function testCurrentValuesNeverContainTheSecretKey(): void
    {
        $this->handler->save(self::input(), ShopScope::context());
        $values = $this->handler->currentValues(ShopScope::context());

        self::assertSame('', $values[ModuleConfig::SECRET_KEY]);
        self::assertSame([3, 4], $values[ConfigFormHandler::FIELD_EXCLUDED_GROUPS_INPUT]);
        self::assertSame(1, $values[ModuleConfig::ENABLED]);
        self::assertStringNotContainsString(self::SK, (string) json_encode($values));
    }

    public function testFormLabelsMatchTheInstallGuide(): void
    {
        $definition = $this->handler->formDefinition([['id' => 3, 'name' => 'B2B <wholesale>']], SecretKeyState::Absent);
        $inputs = $definition[0]['form']['input'];
        self::assertIsArray($inputs);

        $labels = [];
        $hidden = [];
        foreach ($inputs as $input) {
            self::assertIsArray($input);
            if ($input['type'] === 'hidden') {
                $hidden[] = $input['name'];
                continue;
            }
            $labels[] = $input['type'] === 'checkbox' ? $input['values']['query'][0]['name'] : $input['label'];
        }
        self::assertSame([ConfigFormHandler::FIELD_ACTION_TOKEN], $hidden, 'the form carries the module CSRF token');

        self::assertSame([
            'Enable tracking',
            'Public key',
            'Secret key',
            'Clear the secret key',
            'Tracking domain',
            'Conversion value',
            'Cookie consent mode',
            'Consent cookie name',
            'Cash on delivery modules',
            'Customer groups excluded from tracking',
            'Debug mode',
        ], $labels);

        $json = (string) json_encode($definition);
        self::assertStringContainsString('Order total incl. VAT and shipping (default)', $json);
        self::assertStringContainsString('Product revenue only - excl. VAT and shipping', $json);
        self::assertStringContainsString('Wait for consent - browser tracking and server-side order events', $json);
        self::assertStringContainsString('B2B &lt;wholesale&gt;', $json, 'group names are escaped');
        self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $json, 'no long dashes in module UI');
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function input(array $override = []): array
    {
        return array_replace([
            ModuleConfig::ENABLED => '1',
            ModuleConfig::PUBLIC_KEY => self::PK,
            ModuleConfig::SECRET_KEY => self::SK,
            ConfigFormHandler::FIELD_SECRET_CLEAR_INPUT => false,
            ModuleConfig::TRACKING_DOMAIN => 'Pixel.Shop.Example',
            ModuleConfig::VALUE_BASIS => 'net_excl_shipping',
            ModuleConfig::CONSENT_MODE => 'browser',
            ModuleConfig::CONSENT_COOKIE => 'cookie_consent',
            ModuleConfig::COD_MODULES => ' ps_cashondelivery , codfee ',
            ModuleConfig::EXCLUDED_GROUPS => ['3', '4'],
            ModuleConfig::DEBUG => '0',
        ], $override);
    }
}
