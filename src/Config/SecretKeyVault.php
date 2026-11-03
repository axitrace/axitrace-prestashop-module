<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;
use Axitrace\PrestaShop\Adapter\SecretCipherInterface;

/**
 * Stores the workspace secret key encrypted with the shop's own key (PhpEncryption with
 * `_NEW_COOKIE_KEY_`). The plaintext never leaves this class except to the server-to-server
 * client. A key that cannot be decrypted is logged as an error, recorded as the last failure (the
 * configuration page shows "re-enter the secret key") and treated as absent by every caller.
 */
final class SecretKeyVault
{
    public const FAILURE_CODE = 'secret_key_decrypt';

    /**
     * Stored by {@see clear()}. PrestaShop saves an empty value as NULL and Configuration::get()
     * then falls back to the shop group / global value, so an empty value cannot stop a shop from
     * inheriting a parent key; this marker can.
     */
    public const CLEARED = 'cleared';

    public function __construct(
        private readonly ConfigurationStoreInterface $store,
        private readonly SecretCipherInterface $cipher,
        private readonly FailureRecorder $failures,
    ) {
    }

    /** Encrypts and saves a key for the scope; the caller has validated its format. */
    public function store(string $secretKey, ShopScope $scope): void
    {
        if (preg_match(ModuleConfig::SECRET_KEY_PATTERN, $secretKey) !== 1) {
            throw new \InvalidArgumentException('The secret key does not have the sk_live_ format.');
        }

        $this->store->set(ModuleConfig::SECRET_KEY, $this->cipher->encrypt($secretKey), $scope->idShopGroup, $scope->idShop);
        $this->failures->clear(self::FAILURE_CODE, $scope);
    }

    /** Removes the key for the scope; the scope does not inherit a parent key either. */
    public function clear(ShopScope $scope): void
    {
        $this->store->set(ModuleConfig::SECRET_KEY, self::CLEARED, $scope->idShopGroup, $scope->idShop);
        $this->failures->clear(self::FAILURE_CODE, $scope);
    }

    public function read(ShopScope $scope): SecretKeyResult
    {
        $ciphertext = trim((string) $this->store->get(ModuleConfig::SECRET_KEY, $scope->idShopGroup, $scope->idShop));
        if ($ciphertext === '' || $ciphertext === self::CLEARED) {
            return SecretKeyResult::absent();
        }

        try {
            $plaintext = $this->cipher->decrypt($ciphertext);
        } catch (\Throwable $e) {
            $this->failures->record(
                self::FAILURE_CODE,
                sprintf('The secret key could not be decrypted (%s: %s). Re-enter the secret key; purchases are sent without costs until then.', $e::class, $e->getMessage()),
                $scope,
            );

            return SecretKeyResult::undecryptable();
        }

        if ($plaintext === null || preg_match(ModuleConfig::SECRET_KEY_PATTERN, $plaintext) !== 1) {
            $this->failures->record(
                self::FAILURE_CODE,
                'The secret key could not be decrypted, most likely because the shop encryption key changed. Re-enter the secret key; purchases are sent without costs until then.',
                $scope,
            );

            return SecretKeyResult::undecryptable();
        }

        return SecretKeyResult::present($plaintext);
    }
}
