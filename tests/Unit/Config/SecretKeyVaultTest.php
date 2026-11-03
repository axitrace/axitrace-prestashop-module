<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Config;

use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\SecretKeyState;
use Axitrace\PrestaShop\Config\SecretKeyVault;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeCipher;
use Axitrace\PrestaShop\Tests\Unit\Support\FixedClock;
use Axitrace\PrestaShop\Tests\Unit\Support\InMemoryConfigurationStore;
use Axitrace\PrestaShop\Tests\Unit\Support\SpyLogger;
use PHPUnit\Framework\TestCase;

final class SecretKeyVaultTest extends TestCase
{
    private const KEY = 'sk_live_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private InMemoryConfigurationStore $store;
    private SpyLogger $logger;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigurationStore();
        $this->logger = new SpyLogger();
    }

    public function testRoundTripStoresOnlyCiphertext(): void
    {
        $vault = $this->vault(new FakeCipher());
        $vault->store(self::KEY, ShopScope::context());

        $stored = (string) $this->store->get(ModuleConfig::SECRET_KEY);
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString(self::KEY, $stored);

        $result = $vault->read(ShopScope::context());
        self::assertSame(SecretKeyState::Present, $result->state);
        self::assertSame(self::KEY, $result->key);
        self::assertSame([], $this->logger->errors);
    }

    public function testNoKeyIsAbsentWithoutAnyError(): void
    {
        $result = $this->vault(new FakeCipher())->read(ShopScope::context());

        self::assertSame(SecretKeyState::Absent, $result->state);
        self::assertNull($result->key);
        self::assertSame([], $this->logger->errors);
    }

    public function testDecryptFailureIsLoggedRecordedAndTreatedAsAbsentKey(): void
    {
        $this->vault(new FakeCipher('old-shop-key'))->store(self::KEY, ShopScope::context());

        // The shop key changed (PhpEncryption::decrypt returns false -> adapter returns null).
        $vault = $this->vault(new FakeCipher('new-shop-key'));
        $result = $vault->read(ShopScope::context());

        self::assertSame(SecretKeyState::Undecryptable, $result->state);
        self::assertNull($result->key);
        self::assertCount(1, $this->logger->errors);
        self::assertStringContainsString('Re-enter the secret key', $this->logger->errors[0]);

        $failure = json_decode((string) $this->store->get(ModuleConfig::LAST_FAILURE), true);
        self::assertIsArray($failure);
        self::assertSame(SecretKeyVault::FAILURE_CODE, $failure['code']);
        self::assertSame('2026-11-03T12:00:00+00:00', $failure['at']);
    }

    public function testRepeatedFailureWritesTheLastFailureOnce(): void
    {
        $this->vault(new FakeCipher('old'))->store(self::KEY, ShopScope::context());
        $vault = $this->vault(new FakeCipher('new'));
        $vault->read(ShopScope::context());
        $first = $this->store->get(ModuleConfig::LAST_FAILURE);

        $vault->read(ShopScope::context());

        self::assertSame($first, $this->store->get(ModuleConfig::LAST_FAILURE));
    }

    public function testCipherExceptionIsLoggedWithClassAndMessage(): void
    {
        $this->vault(new FakeCipher())->store(self::KEY, ShopScope::context());
        $cipher = new FakeCipher();
        $cipher->decryptThrows = new \RuntimeException('_NEW_COOKIE_KEY_ is not defined');

        $result = $this->vault($cipher)->read(ShopScope::context());

        self::assertSame(SecretKeyState::Undecryptable, $result->state);
        self::assertStringContainsString('RuntimeException: _NEW_COOKIE_KEY_ is not defined', $this->logger->errors[0]);
    }

    public function testStoringANewKeyClearsTheDecryptFailure(): void
    {
        $this->vault(new FakeCipher('old'))->store(self::KEY, ShopScope::context());
        $vault = $this->vault(new FakeCipher('new'));
        $vault->read(ShopScope::context());

        $vault->store(self::KEY, ShopScope::context());

        self::assertNull($this->store->get(ModuleConfig::LAST_FAILURE));
        self::assertSame(SecretKeyState::Present, $vault->read(ShopScope::context())->state);
    }

    public function testClearedShopDoesNotInheritTheParentKey(): void
    {
        $vault = $this->vault(new FakeCipher());
        $vault->store(self::KEY, ShopScope::context());
        $vault->clear(ShopScope::shop(1, 2));

        self::assertSame(SecretKeyState::Absent, $vault->read(ShopScope::shop(1, 2))->state);
        self::assertSame(SecretKeyState::Present, $vault->read(ShopScope::shop(1, 3))->state);
    }

    public function testMalformedKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->vault(new FakeCipher())->store('sk_live_short', ShopScope::context());
    }

    private function vault(FakeCipher $cipher): SecretKeyVault
    {
        return new SecretKeyVault($this->store, $cipher, new FailureRecorder($this->store, $this->logger, new FixedClock()));
    }
}
