<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The product shown on a product page, read by the PrestaShop adapter. Prices are unit prices in
 * the visitor's currency, with or without tax per the configured value basis.
 */
final class ProductView
{
    /**
     * @param array<int, float> $variantPrices combination id => unit price (bounded list)
     */
    public function __construct(
        public readonly int $productId,
        public readonly int $variantId,
        public readonly string $name,
        public readonly string $sku,
        public readonly float $price,
        public readonly string $currency,
        public readonly string $category,
        public readonly string $brand,
        public readonly array $variantPrices = [],
    ) {
    }
}
