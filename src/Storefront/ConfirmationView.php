<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The order shown on the order confirmation page. The browser purchase (value, products, event
 * salt) is added by the purchase pipeline only when the order is send-eligible.
 */
final class ConfirmationView
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderReference,
        public readonly string $currency,
    ) {
    }
}
