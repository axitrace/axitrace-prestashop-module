<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Refund;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** One line of {@see SentPurchase}: as it was when the purchase was sent, gross, order currency. */
final class SentLine
{
    public function __construct(
        public readonly int $idOrderDetail,
        public readonly int $productId,
        public readonly int $variantId,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly float $unitPriceTaxIncl,
    ) {
    }
}
