<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Normalizer;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Purchase\PurchaseLine;

/**
 * The NET purchase cost per unit of one order line, in the ORDER currency (A37).
 *
 * Source, first positive value wins:
 *  1. `order_detail.purchase_supplier_price`: the default supplier's price when the product has one,
 *     otherwise the product's cost price, frozen when the order was placed;
 *  2. `order_detail.original_wholesale_price`: the cost price ("Cost price" in the back office,
 *     combination-aware) frozen when the order was placed;
 *  3. the product's or combination's current `wholesale_price`.
 *
 * All three are stored in the shop's default currency, so the result is multiplied by
 * `orders.conversion_rate` (T1 finding 5). A line without a positive cost, or an order without a
 * usable conversion rate, gets no cost at all: AxiTrace then applies the workspace's default
 * margin, which is better than a wrong cost of 0.
 *
 * Pure: no I/O.
 */
final class PurchaseCostResolver
{
    public function unitCost(PurchaseLine $line, float $conversionRate): ?float
    {
        if (!is_finite($conversionRate) || $conversionRate <= 0.0) {
            return null;
        }

        foreach ([$line->purchaseSupplierPrice, $line->originalWholesalePrice, $line->currentWholesalePrice] as $cost) {
            if (is_finite($cost) && $cost > 0.0) {
                return round($cost * $conversionRate, 4);
            }
        }

        return null;
    }
}
