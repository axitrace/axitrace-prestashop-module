<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Purchase\OrderStateIds;
use Axitrace\PrestaShop\Purchase\PurchaseOrder;

/** Reads PrestaShop orders into plain values for the purchase pipeline. */
interface OrderReaderInterface
{
    /** The order, or null when it does not exist. */
    public function read(int $idOrder): ?PurchaseOrder;

    /** PS_OS_ERROR, PS_OS_CANCELED, PS_OS_REFUND of the order's shop. */
    public function stateIds(int $idShopGroup, int $idShop): OrderStateIds;
}
