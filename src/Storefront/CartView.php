<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The visitor's cart on the cart and checkout pages. `value` follows the configured value basis.
 */
final class CartView
{
    /**
     * @param list<CartLine> $lines
     */
    public function __construct(
        public readonly float $value,
        public readonly string $currency,
        public readonly array $lines,
    ) {
    }
}
