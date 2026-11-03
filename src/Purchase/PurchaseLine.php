<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Purchase;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** One `order_detail` line, amounts in the order currency. */
final class PurchaseLine
{
    public function __construct(
        public readonly int $productId,
        public readonly int $variantId,
        public readonly string $reference,
        public readonly string $name,
        public readonly int $quantity,
        public readonly float $unitPriceTaxIncl,
        public readonly float $unitPriceTaxExcl,
        public readonly string $category = '',
        public readonly string $brand = '',
    ) {
    }
}
