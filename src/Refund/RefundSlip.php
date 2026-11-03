<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Refund;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * One credit slip (`order_slip` with its `order_slip_detail` lines). Amounts are in the ORDER
 * currency, as PrestaShop stores them (T1 finding 6); `total_products_tax_incl` excludes shipping,
 * which is `total_shipping_tax_incl`.
 */
final class RefundSlip
{
    /**
     * @param list<RefundSlipLine> $lines
     */
    public function __construct(
        public readonly int $idOrderSlip,
        public readonly int $idOrder,
        public readonly float $totalProductsTaxIncl,
        public readonly float $totalShippingTaxIncl,
        public readonly \DateTimeImmutable $createdAt,
        public readonly array $lines,
    ) {
    }
}
