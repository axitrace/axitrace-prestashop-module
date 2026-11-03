<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\SecretCipherInterface;

/** Reversible fake bound to a key, like PhpEncryption: another key cannot decrypt. */
final class FakeCipher implements SecretCipherInterface
{
    public ?\Throwable $decryptThrows = null;

    public function __construct(private readonly string $key = 'shop-key-1')
    {
    }

    public function encrypt(string $plaintext): string
    {
        return base64_encode($this->key . '|' . $plaintext);
    }

    public function decrypt(string $ciphertext): ?string
    {
        if ($this->decryptThrows !== null) {
            throw $this->decryptThrows;
        }

        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || !str_starts_with($decoded, $this->key . '|')) {
            return null;
        }

        return substr($decoded, strlen($this->key) + 1);
    }
}
