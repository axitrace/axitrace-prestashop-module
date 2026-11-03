<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Config;

use Axitrace\PrestaShop\Config\EndpointError;
use Axitrace\PrestaShop\Config\EndpointResolver;
use Axitrace\PrestaShop\Config\ResolvedEndpoints;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeEnvironment;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHostResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EndpointResolverTest extends TestCase
{
    private const DEV_ENV = [
        'AXITRACE_DEV_API_BASE_URL' => 'http://velitrack-ingestion-api:8080/',
        'AXITRACE_DEV_SDK_BASE_URL' => 'http://localhost:8080',
    ];

    public function testDefaultDomainNeedsNoDnsLookup(): void
    {
        $dns = new FakeHostResolver();
        $resolved = (new EndpointResolver(new FakeEnvironment(), $dns))->resolve('stat.axitrace.com');

        self::assertSame('https://stat.axitrace.com', $resolved->apiBaseUrl);
        self::assertSame('https://stat.axitrace.com', $resolved->sdkBaseUrl);
        self::assertNull($resolved->error);
        self::assertFalse($resolved->overrideActive);
        self::assertSame([], $dns->lookups);
    }

    public function testEmptyDomainUsesTheDefault(): void
    {
        $resolved = (new EndpointResolver(new FakeEnvironment(), new FakeHostResolver()))->resolve('  ');

        self::assertSame('https://stat.axitrace.com', $resolved->apiBaseUrl);
        self::assertNull($resolved->error);
    }

    public function testCustomPublicHostIsUsedOverHttpsForApiAndSdk(): void
    {
        $dns = new FakeHostResolver(['pixel.shop.example' => ['93.184.216.34']]);
        $resolved = (new EndpointResolver(new FakeEnvironment(), $dns))->resolve(' Pixel.Shop.Example ');

        self::assertSame('https://pixel.shop.example', $resolved->apiBaseUrl);
        self::assertSame('https://pixel.shop.example', $resolved->sdkBaseUrl);
        self::assertNull($resolved->error);
    }

    /**
     * @return iterable<string, array{string, array<string, list<string>>, EndpointError}>
     */
    public static function refusedDomains(): iterable
    {
        yield 'http scheme' => ['http://pixel.shop.example', ['pixel.shop.example' => ['93.184.216.34']], EndpointError::InsecureScheme];
        yield 'https scheme is not host-only' => ['https://pixel.shop.example', [], EndpointError::InvalidHost];
        yield 'IPv4 literal' => ['93.184.216.34', [], EndpointError::IpLiteral];
        yield 'IPv6 literal' => ['[2606:2800:220:1::248]', [], EndpointError::IpLiteral];
        yield 'path' => ['pixel.shop.example/track', [], EndpointError::InvalidHost];
        yield 'port' => ['pixel.shop.example:8443', [], EndpointError::InvalidHost];
        yield 'single label' => ['localhost', [], EndpointError::InvalidHost];
        yield 'unresolvable' => ['nowhere.shop.example', [], EndpointError::Unresolvable];
        yield 'private 10/8' => ['pixel.shop.example', ['pixel.shop.example' => ['10.0.0.5']], EndpointError::PrivateAddress];
        yield 'private 192.168/16' => ['pixel.shop.example', ['pixel.shop.example' => ['192.168.1.20']], EndpointError::PrivateAddress];
        yield 'loopback' => ['pixel.shop.example', ['pixel.shop.example' => ['127.0.0.1']], EndpointError::PrivateAddress];
        yield 'link-local metadata' => ['pixel.shop.example', ['pixel.shop.example' => ['169.254.169.254']], EndpointError::PrivateAddress];
        yield 'one private among public' => ['pixel.shop.example', ['pixel.shop.example' => ['93.184.216.34', '172.16.0.9']], EndpointError::PrivateAddress];
    }

    /**
     * @param array<string, list<string>> $records
     */
    #[DataProvider('refusedDomains')]
    public function testRefusedDomainFallsBackToDefaultWithAnError(string $domain, array $records, EndpointError $error): void
    {
        $resolved = (new EndpointResolver(new FakeEnvironment(), new FakeHostResolver($records)))->resolve($domain);

        self::assertSame('https://stat.axitrace.com', $resolved->apiBaseUrl);
        self::assertSame('https://stat.axitrace.com', $resolved->sdkBaseUrl);
        self::assertSame($error, $resolved->error);
        self::assertSame(strtolower(trim($domain)), $resolved->rejectedDomain);
        self::assertFalse($resolved->overrideActive);
    }

    public function testOverrideIsIgnoredWithoutDevMode(): void
    {
        $resolved = (new EndpointResolver(new FakeEnvironment(false, self::DEV_ENV), new FakeHostResolver()))->resolve('stat.axitrace.com');

        self::assertSame('https://stat.axitrace.com', $resolved->apiBaseUrl);
        self::assertSame('https://stat.axitrace.com', $resolved->sdkBaseUrl);
        self::assertFalse($resolved->overrideActive);
    }

    public function testDevModeWithoutEnvUsesTheNormalPath(): void
    {
        $resolved = (new EndpointResolver(new FakeEnvironment(true), new FakeHostResolver()))->resolve('stat.axitrace.com');

        self::assertSame('https://stat.axitrace.com', $resolved->apiBaseUrl);
        self::assertFalse($resolved->overrideActive);
    }

    public function testOverrideIsHonouredVerbatimInDevModeWithEnv(): void
    {
        $resolved = (new EndpointResolver(new FakeEnvironment(true, self::DEV_ENV), new FakeHostResolver()))->resolve('stat.axitrace.com');

        self::assertEquals(
            new ResolvedEndpoints('http://velitrack-ingestion-api:8080', 'http://localhost:8080', null, '', true),
            $resolved,
        );
    }

    public function testOverrideOfTheApiAloneKeepsTheResolvedSdkHost(): void
    {
        $resolved = (new EndpointResolver(
            new FakeEnvironment(true, ['AXITRACE_DEV_API_BASE_URL' => 'http://10.0.0.2:8080']),
            new FakeHostResolver(),
        ))->resolve('stat.axitrace.com');

        self::assertSame('http://10.0.0.2:8080', $resolved->apiBaseUrl);
        self::assertSame('https://stat.axitrace.com', $resolved->sdkBaseUrl);
        self::assertTrue($resolved->overrideActive);
    }

    public function testIpv6PrivateRangesAreRefused(): void
    {
        self::assertTrue(EndpointResolver::isPrivateOrReservedIp('::1'));
        self::assertTrue(EndpointResolver::isPrivateOrReservedIp('fd00::1'));
        self::assertTrue(EndpointResolver::isPrivateOrReservedIp('fe80::1'));
        self::assertFalse(EndpointResolver::isPrivateOrReservedIp('2606:2800:220:1::248'));
    }
}
