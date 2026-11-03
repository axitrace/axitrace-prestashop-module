<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Purchase;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A PrestaShop order as the purchase pipeline needs it, read once by the order reader (the only
 * class touching the Order object). Every amount is in the order currency; `dateAdd` is the raw
 * `orders.date_add`, stored in the shop's PS_TIMEZONE (`timezone`).
 */
final class PurchaseOrder
{
    /**
     * @param list<PurchaseLine> $lines
     */
    public function __construct(
        public readonly int $idOrder,
        public readonly int $idShop,
        public readonly int $idShopGroup,
        public readonly int $idCart,
        public readonly string $reference,
        public readonly string $paymentModule,
        public readonly string $currency,
        public readonly float $totalPaidTaxIncl,
        public readonly float $totalPaidTaxExcl,
        public readonly float $totalShippingTaxIncl,
        public readonly float $totalShippingTaxExcl,
        public readonly string $dateAdd,
        public readonly string $timezone,
        public readonly int $currentStateId,
        public readonly bool $currentStatePaid,
        public readonly ?int $customerDefaultGroupId,
        public readonly PurchaseCustomer $customer,
        public readonly array $lines,
    ) {
    }
}
