<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Refund;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** One product line of a credit slip; `amountTaxIncl` is the line total, order currency. */
final class RefundSlipLine
{
    public function __construct(
        public readonly int $idOrderDetail,
        public readonly int $productId,
        public readonly string $reference,
        public readonly int $quantity,
        public readonly float $amountTaxIncl,
    ) {
    }
}
