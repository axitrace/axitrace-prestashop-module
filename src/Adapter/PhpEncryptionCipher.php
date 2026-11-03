<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PrestaShop's PhpEncryption keyed with `_NEW_COOKIE_KEY_`. PhpEncryption::decrypt() returns false
 * (it does not throw) when the key does not match, so false is mapped to null here.
 */
final class PhpEncryptionCipher implements SecretCipherInterface
{
    public function encrypt(string $plaintext): string
    {
        $encrypted = $this->engine()->encrypt($plaintext);
        if ($encrypted === '') {
            throw new \RuntimeException('PhpEncryption returned no ciphertext.');
        }

        return $encrypted;
    }

    public function decrypt(string $ciphertext): ?string
    {
        $plaintext = $this->engine()->decrypt($ciphertext);

        return is_string($plaintext) ? $plaintext : null;
    }

    private function engine(): \PhpEncryption
    {
        if (!defined('_NEW_COOKIE_KEY_')) {
            throw new \RuntimeException('_NEW_COOKIE_KEY_ is not defined; the shop has no encryption key.');
        }

        return new \PhpEncryption((string) constant('_NEW_COOKIE_KEY_'));
    }
}
