<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Hook\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** The order of an `actionValidateOrder` call, read from the PrestaShop Order object. */
final class PlacedOrder
{
    public function __construct(
        public readonly int $idOrder,
        public readonly int $idShop,
        public readonly int $idShopGroup,
        public readonly int $idCart,
        public readonly string $reference,
    ) {
    }
}
