<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Supported platform: PrestaShop 8.1 or newer on PHP 8.1 or newer (D1).
 */
final class VersionGuard
{
    public const MIN_PRESTASHOP = '8.1.0';
    public const MIN_PHP_VERSION_ID = 80100;

    public static function isSupported(string $prestaShopVersion, int $phpVersionId): bool
    {
        return $phpVersionId >= self::MIN_PHP_VERSION_ID
            && version_compare($prestaShopVersion, self::MIN_PRESTASHOP, '>=');
    }
}
