<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface SecureRandomInterface
{
    /** Hex encoding of the given number of cryptographically secure random bytes. */
    public function hex(int $bytes): string;
}
