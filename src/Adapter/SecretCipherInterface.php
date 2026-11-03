<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Symmetric encryption bound to the shop's own key. {@see decrypt()} returns null when the value
 * cannot be decrypted (for example after the shop key changed).
 */
interface SecretCipherInterface
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): ?string;
}
