<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Domain;

use Axitrace\PrestaShop\Adapter\HttpResponse;
use Axitrace\PrestaShop\Admin\DomainStatusPanel;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\SecretKeyVault;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Domain\ShopHosts;
use Axitrace\PrestaShop\Domain\StoreDomainClaimer;
use Axitrace\PrestaShop\Domain\StoreDomainClaimTrigger;
use Axitrace\PrestaShop\Domain\StoreDomainStatus;
use Axitrace\PrestaShop\Tests\Unit\Support\EchoTranslator;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeCipher;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeEnvironment;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHttpTransport;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeScheduler;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeStoreHostsReader;
use Axitrace\PrestaShop\Tests\Unit\Support\FixedClock;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use Axitrace\PrestaShop\Tests\Unit\Support\SpyLogger;
use PHPUnit\Framework\TestCase;

final class StoreDomainClaimerTest extends TestCase
{
    private const PK_1 = 'pk_live_11111111111111111111111111111111';
    private const SK_1 = 'sk_live_' . '1111111111111111111111111111111111111111111111111111111111111111';
    private const PK_2 = 'pk_live_22222222222222222222222222222222';

    private InMemoryConfigurationStore $store;
    private SecretKeyVault $vault;
    private FakeHttpTransport $transport;
    private FakeStoreHostsReader $shops;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigurationStore();
        $this->vault = new SecretKeyVault($this->store, new FakeCipher(), $this->failures());
        $this->transport = new FakeHttpTransport();
        $this->shops = new FakeStoreHostsReader();
    }

    private function failures(): FailureRecorder
    {
        return new FailureRecorder($this->store, new SpyLogger(), new FixedClock());
    }

    private function claimer(?FakeEnvironment $environment = null): StoreDomainClaimer
    {
        return new StoreDomainClaimer(
            $this->shops,
            new ModuleConfig($this->store),
            $this->vault,
            $this->store,
            $this->transport,
            $environment ?? new FakeEnvironment(),
            $this->failures(),
            new FixedClock(),
            '1.1.0',
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string|null}> $hosts host, status, reason
     */
    private static function answer(array $hosts): HttpResponse
    {
        $entries = array_map(static fn (array $h): array => ['host' => $h[0], 'status' => $h[1], 'reason' => $h[2] ?? null, 'browser_events_rejected' => $h[1] !== 'verified'], $hosts);

        return new HttpResponse(200, ['content-type' => 'application/json'], (string) json_encode(['success' => true, 'hosts' => $entries]));
    }

    private function storedStatus(int $idShopGroup, int $idShop): ?StoreDomainStatus
    {
        return StoreDomainStatus::fromJson($this->store->get(ModuleConfig::DOMAIN_STATUS, $idShopGroup, $idShop));
    }

    public function testClaimRequestShapeWithoutSecretKeyHasNoAuthorization(): void
    {
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK_1);
        $this->shops->shops = [new ShopHosts(1, 1, ['shop.example', 'www.shop.example'])];
        $this->transport->willAnswer(self::answer([['shop.example', 'verified'], ['www.shop.example', 'pending']]));

        $this->claimer()->claimAll();

        self::assertCount(1, $this->transport->requests);
        $request = $this->transport->requests[0];
        self::assertSame('https://axitrace.com/api/public/store-domains/claim', $request['url'], 'axitrace.com, never the tracking domain');
        self::assertSame(['public_key' => self::PK_1, 'platform' => 'prestashop', 'hosts' => ['shop.example', 'www.shop.example']], $request['payload']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertArrayNotHasKey('Authorization', $request['headers']);
        self::assertSame([StoreDomainClaimer::CONNECT_TIMEOUT_SECONDS, StoreDomainClaimer::TOTAL_TIMEOUT_SECONDS], [$request['connect'], $request['total']]);
    }

    public function testSecretKeyTravelsOnlyAsBasicHeader(): void
    {
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK_1);
        $this->vault->store(self::SK_1, ShopScope::shop(1, 1));
        $this->shops->shops = [new ShopHosts(1, 1, ['shop.example'])];
        $this->transport->willAnswer(self::answer([['shop.example', 'verified']]));

        $this->claimer()->claimAll();

        $request = $this->transport->requests[0];
        self::assertSame('Basic ' . base64_encode(self::SK_1 . ':'), $request['headers']['Authorization']);
        self::assertStringNotContainsString(self::SK_1, $request['body'], 'never in the body');
        self::assertStringNotContainsString(self::SK_1, $request['url']);
    }

    public function testResponseIsStoredPerShopAndShopsAreGroupedByKey(): void
    {
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK_1);
        $this->store->set(ModuleConfig::PUBLIC_KEY, self::PK_2, 1, 3);
        $this->store->set(ModuleConfig::PUBLIC_KEY, '', 1, 4);
        $this->shops->shops = [
            new ShopHosts(1, 1, ['one.example']),
            new ShopHosts(1, 2, ['two.example']),
            new ShopHosts(1, 3, ['three.example']),
            new ShopHosts(1, 4, ['four.example']),
        ];
        $this->transport->willAnswer(
            self::answer([['one.example', 'verified'], ['two.example', 'failed', 'proof_not_found']]),
            self::answer([['three.example', 'rejected', 'held_by_other_workspace']]),
        );

        $statuses = $this->claimer()->claimAll();

        self::assertCount(2, $this->transport->requests, 'one claim per public key; shop 4 has none');
        self::assertSame(['one.example', 'two.example'], $this->transport->requests[0]['payload']['hosts']);
        self::assertSame(self::PK_2, $this->transport->requests[1]['payload']['public_key']);
        self::assertSame(['three.example'], $this->transport->requests[1]['payload']['hosts']);

        self::assertSame([['host' => 'one.example', 'status' => 'verified', 'reason' => null, 'browser_events_rejected' => false]], $this->storedStatus(1, 1)?->hosts);
        self::assertSame('proof_not_found', $this->storedStatus(1, 2)?->hosts[0]['reason']);
        self::assertSame('held_by_other_workspace', $this->storedStatus(1, 3)?->hosts[0]['reason']);
        self::assertSame(StoreDomainStatus::ERROR_NO_PUBLIC_KEY, $this->storedStatus(1, 4)?->error);
        self::assertSame(StoreDomainStatus::ERROR_NO_PUBLIC_KEY, $statuses[4]->error);
        self::assertSame((string) ((new FixedClock())->now()->getTimestamp() + 86400), $this->store->get(ModuleConfig::DOMAIN_CLAIM_DUE_AT, 0, 0), 'next claim in a day');
    }

    public function testAtMostTenHostsPerClaim(): void
    {
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK_1);
        $hosts = [];
        for ($i = 1; $i <= 12; ++$i) {
            $hosts[] = 'shop' . $i . '.example';
        }
        $this->shops->shops = [new ShopHosts(1, 1, $hosts)];

        $this->claimer()->claimAll();

        self::assertCount(2, $this->transport->requests);
        self::assertCount(10, $this->transport->requests[0]['payload']['hosts']);
        self::assertCount(2, $this->transport->requests[1]['payload']['hosts']);
    }

    public function testRefusedClaimKeepsThePreviousHostsAndStoresTheErrorCode(): void
    {
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK_1);
        $this->shops->shops = [new ShopHosts(1, 1, ['shop.example'])];
        $this->transport->willAnswer(
            self::answer([['shop.example', 'verified']]),
            new HttpResponse(429, [], '{"success":false,"error":"rate_limited"}'),
            FakeHttpTransport::timeout(),
            new HttpResponse(502, [], '<html>Bad gateway</html>'),
        );
        $claimer = $this->claimer();

        $claimer->claimAll();
        $claimer->claimAll();
        $refused = $this->storedStatus(1, 1);
        self::assertNotNull($refused);
        self::assertSame('rate_limited', $refused->error);
        self::assertSame('verified', $refused->hosts[0]['status'], 'the last known answer stays');

        $claimer->claimAll();
        $unreachable = $this->storedStatus(1, 1);
        self::assertSame(StoreDomainStatus::ERROR_UNREACHABLE, $unreachable?->error);
        self::assertNotNull($this->failures()->allWithGlobal(ShopScope::shop(1, 1))[0] ?? null, 'shown in Connection health');

        $claimer->claimAll();
        $badGateway = $this->storedStatus(1, 1);
        self::assertSame('http_502', $badGateway?->error);
    }

    public function testShopWithoutHostAndDeveloperOverride(): void
    {
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK_1);
        $this->shops->shops = [new ShopHosts(1, 1, [])];
        $this->claimer()->claimAll();
        self::assertSame([], $this->transport->requests);
        self::assertSame(StoreDomainStatus::ERROR_NO_STORE_HOST, $this->storedStatus(1, 1)?->error);

        $env = [StoreDomainClaimer::ENV_ADMIN_OVERRIDE => 'http://velitrack-admin:8000/'];
        self::assertSame('http://velitrack-admin:8000/api/public/store-domains/claim', $this->claimer(new FakeEnvironment(true, $env))->claimUrl());
        self::assertSame('https://axitrace.com/api/public/store-domains/claim', $this->claimer(new FakeEnvironment(false, $env))->claimUrl(), 'never outside _PS_MODE_DEV_');
    }

    public function testTriggerClaimsOnceADayAndRightAwayWhenNeverClaimed(): void
    {
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK_1);
        $this->shops->shops = [new ShopHosts(1, 1, ['shop.example'])];
        $scheduler = new FakeScheduler(false);
        $trigger = new StoreDomainClaimTrigger($this->store, $this->claimer(), $scheduler, new FixedClock());

        self::assertFalse($trigger->maybeSchedule(true), 'a storefront request without FastCGI never waits for the claim');
        self::assertTrue($trigger->maybeSchedule(false), 'never claimed: due now');
        self::assertFalse($trigger->maybeSchedule(false), 'already scheduled for today');
        $scheduler->runDeferred();
        self::assertCount(1, $this->transport->requests);

        $trigger->claimSoon();
        self::assertTrue($trigger->maybeSchedule(false), 'after an upgrade');
    }

    public function testPanelExplainsEachOutcome(): void
    {
        $shop = new ShopHosts(1, 1, ['shop.example', 'www.shop.example']);
        $rows = (new DomainStatusPanel(new EchoTranslator()))->rows([
            ['shop' => $shop, 'status' => new StoreDomainStatus(1, [
                ['host' => 'shop.example', 'status' => 'verified', 'reason' => null, 'browser_events_rejected' => false],
                ['host' => 'www.shop.example', 'status' => 'failed', 'reason' => 'proof_signature_invalid', 'browser_events_rejected' => true],
            ], 'unreachable')],
            ['shop' => new ShopHosts(1, 2, ['two.example']), 'status' => null],
        ]);

        self::assertSame(['ok', 'error', 'error', 'warning'], array_column($rows, 'state'));
        self::assertStringContainsString('same AxiTrace workspace', $rows[1]['message']);
        self::assertStringContainsString('could not be reached', $rows[2]['message']);
        self::assertSame('two.example', $rows[3]['host']);
    }
}
