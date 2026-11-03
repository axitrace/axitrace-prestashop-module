<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class CartLine
{
    public function __construct(
        public readonly int $productId,
        public readonly int $variantId,
        public readonly string $name,
        public readonly string $sku,
        public readonly float $price,
        public readonly int $quantity,
        public readonly string $category,
        public readonly string $brand,
    ) {
    }
}
