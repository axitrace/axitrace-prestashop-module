<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The shop scope a setting is read for. Null ids mean "the current shop context"; the order
 * pipeline always passes the order's own ids.
 */
final class ShopScope
{
    public function __construct(
        public readonly ?int $idShopGroup = null,
        public readonly ?int $idShop = null,
    ) {
    }

    public static function context(): self
    {
        return new self();
    }

    public static function shop(int $idShopGroup, int $idShop): self
    {
        return new self($idShopGroup, $idShop);
    }
}
