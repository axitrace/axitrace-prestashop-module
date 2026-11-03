<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class NativeSecureRandom implements SecureRandomInterface
{
    public function hex(int $bytes): string
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('At least one random byte is required.');
        }

        return bin2hex(random_bytes($bytes));
    }
}
