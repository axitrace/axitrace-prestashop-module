<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Purchase\PurchaseLine;
use Axitrace\PrestaShop\Refund\RefundSlip;

/** Reads the PrestaShop refund sources: credit slips and the live order lines. */
interface RefundReaderInterface
{
    /**
     * A credit slip of the order. With a null id (PrestaShop 8.x passes no slip to
     * actionOrderSlipAdd) the newest slip of the order: MAX(id_order_slip).
     */
    public function slip(int $idOrder, ?int $idOrderSlip): ?RefundSlip;

    /** Number of credit slips of the order. */
    public function slipCount(int $idOrder): int;

    /** The order line as it is now, or null when it was deleted (a fully cancelled line). */
    public function currentLine(int $idOrderDetail): ?PurchaseLine;
}
