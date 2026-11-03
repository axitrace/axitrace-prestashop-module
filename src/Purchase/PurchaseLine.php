<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Purchase;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * One `order_detail` line. Prices are in the order currency; the three cost columns are NET and in
 * the shop's DEFAULT currency, as PrestaShop stores them (0 = not set), and are converted by
 * {@see \Axitrace\PrestaShop\Normalizer\PurchaseCostResolver}.
 */
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
        public readonly int $idOrderDetail = 0,
        /** `order_detail.purchase_supplier_price`: supplier price, else the cost price at order time. */
        public readonly float $purchaseSupplierPrice = 0.0,
        /** `order_detail.original_wholesale_price`: the cost price at order time (combination-aware). */
        public readonly float $originalWholesalePrice = 0.0,
        /** The product's or combination's cost price now (`wholesale_price`). */
        public readonly float $currentWholesalePrice = 0.0,
    ) {
    }
}
