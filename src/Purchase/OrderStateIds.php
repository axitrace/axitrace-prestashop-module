<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Purchase;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** The shop's PS_OS_ERROR, PS_OS_CANCELED and PS_OS_REFUND state ids (0 = not configured). */
final class OrderStateIds
{
    public function __construct(
        public readonly int $error,
        public readonly int $canceled,
        public readonly int $refund,
    ) {
    }
}
