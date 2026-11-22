<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Domain;

use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\SecretKeyVault;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Domain\DomainProofResponder;
use Axitrace\PrestaShop\Domain\ShopHosts;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeCipher;
use Axitrace\PrestaShop\Tests\Unit\Support\FixedClock;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use Axitrace\PrestaShop\Tests\Unit\Support\SpyLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainProofResponderTest extends TestCase
{
    private const PK_1 = 'pk_live_11111111111111111111111111111111';
    private const SK_1 = 'sk_live_' . '1111111111111111111111111111111111111111111111111111111111111111';
    private const PK_2 = 'pk_live_22222222222222222222222222222222';
    private const NONCE = 'AbCdEfGh_ijkl-MNOP1234';

    private InMemoryConfigurationStore $store;
    private SecretKeyVault $vault;
    private DomainProofResponder $responder;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigurationStore();
        $this->vault = new SecretKeyVault($this->store, new FakeCipher(), new FailureRecorder($this->store, new SpyLogger(), new FixedClock()));
        $this->responder = new DomainProofResponder(new ModuleConfig($this->store), $this->vault);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNonces(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['abcdefghijklmno'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'dot' => ['abcdefghijklmnop.q'];
        yield 'html' => ['<script>alert(1)</script>'];
        yield 'space' => ['abcdefgh ijklmnopq'];
    }

    #[DataProvider('badNonces')]
    public function testMalformedNonceIs400(string $nonce): void
    {
        $this->store->set(ModuleConfig::PUBLIC_KEY, self::PK_1, 1, 1);

        $answer = $this->responder->answer($nonce, new ShopHosts(1, 1, ['shop.example']), 'shop.example');

        self::assertSame(400, $answer['status']);
        self::assertSame('{"error":"invalid_nonce"}', $answer['body']);
    }

    public function testNonceAtTheLengthBoundsIsAccepted(): void
    {
        $this->store->set(ModuleConfig::PUBLIC_KEY, self::PK_1, 1, 1);
        $shop = new ShopHosts(1, 1, ['shop.example']);

        self::assertSame(200, $this->responder->answer(str_repeat('a', 16), $shop, 'shop.example')['status']);
        self::assertSame(200, $this->responder->answer(str_repeat('Z', 128), $shop, 'shop.example')['status']);
    }

    public function testShopWithoutPublicKeyIs404(): void
    {
        $this->store->set(ModuleConfig::PUBLIC_KEY, 'not-a-key', 1, 1);

        $answer = $this->responder->answer(self::NONCE, new ShopHosts(1, 1, ['shop.example']), 'shop.example');

        self::assertSame(404, $answer['status']);
        self::assertSame('{"error":"not_configured"}', $answer['body']);
    }

    public function testWithoutSecretKeyTheBodyHasExactlyTheFourProofKeys(): void
    {
        $this->store->set(ModuleConfig::PUBLIC_KEY, self::PK_1, 1, 1);

        $answer = $this->responder->answer(self::NONCE, new ShopHosts(1, 1, ['shop.example']), 'shop.example');

        self::assertSame(200, $answer['status']);
        self::assertSame(
            ['axitrace_domain_proof' => 1, 'public_key' => self::PK_1, 'nonce' => self::NONCE, 'platform' => 'prestashop'],
            json_decode($answer['body'], true),
        );
    }

    public function testWithSecretKeyTheBodyIsSignedOverNonceHostAndPublicKey(): void
    {
        $this->store->set(ModuleConfig::PUBLIC_KEY, self::PK_1, 1, 1);
        $this->vault->store(self::SK_1, ShopScope::shop(1, 1));

        $answer = $this->responder->answer(self::NONCE, new ShopHosts(1, 1, ['www.shop.example', 'shop.example']), 'Shop.Example:443');

        $body = json_decode($answer['body'], true);
        self::assertIsArray($body);
        self::assertSame(['axitrace_domain_proof', 'public_key', 'nonce', 'platform', 'signature'], array_keys($body));
        // The same HMAC the AxiTrace server computes (StoreDomainProofFetcher::signature()).
        self::assertSame(hash_hmac('sha256', self::NONCE . '|shop.example|' . self::PK_1, self::SK_1), $body['signature'], 'the request host, normalized, as it is a claimed host');
    }

    public function testAForeignRequestHostIsNeverSigned(): void
    {
        $this->store->set(ModuleConfig::PUBLIC_KEY, self::PK_1, 1, 1);
        $this->vault->store(self::SK_1, ShopScope::shop(1, 1));

        $answer = $this->responder->answer(self::NONCE, new ShopHosts(1, 1, ['shop.example']), 'evil.example');

        $body = json_decode($answer['body'], true);
        self::assertIsArray($body);
        self::assertSame(DomainProofResponder::signature(self::SK_1, self::NONCE, 'shop.example', self::PK_1), $body['signature'], 'the shop\'s own host instead');
    }

    public function testEachShopAnswersWithItsOwnKey(): void
    {
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK_1);
        $this->vault->store(self::SK_1, ShopScope::shop(1, 1));
        $this->store->set(ModuleConfig::PUBLIC_KEY, self::PK_2, 1, 2);

        $shop1 = json_decode($this->responder->answer(self::NONCE, new ShopHosts(1, 1, ['one.example']), 'one.example')['body'], true);
        $shop2 = json_decode($this->responder->answer(self::NONCE, new ShopHosts(1, 2, ['two.example']), 'two.example')['body'], true);

        self::assertIsArray($shop1);
        self::assertIsArray($shop2);
        self::assertSame(self::PK_1, $shop1['public_key']);
        self::assertArrayHasKey('signature', $shop1);
        self::assertSame(self::PK_2, $shop2['public_key']);
        self::assertArrayNotHasKey('signature', $shop2, 'shop 2 has no secret key of its own');
    }

    public function testHostNormalization(): void
    {
        self::assertSame('shop.example', ShopHosts::normalizeHost(' Shop.Example.:8080 '));
        self::assertSame('shop.example', ShopHosts::normalizeHost('https://shop.example/fr/'));
        self::assertSame('', ShopHosts::normalizeHost('shop_example.com'));
        self::assertSame(['shop.example', 'www.shop.example'], ShopHosts::fromDomains(1, 1, ['shop.example', 'SHOP.example', 'www.shop.example', ''])->hosts);
    }
}
