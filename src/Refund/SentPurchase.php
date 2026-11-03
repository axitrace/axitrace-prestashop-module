<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Refund;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Purchase\PurchaseOrder;

/**
 * What the module sent as the purchase of an order, kept in `axitrace_order.purchase_snapshot`
 * from the moment the purchase was claimed: the order currency, the GROSS order total and every
 * line (by `id_order_detail`) with its quantity and gross unit price.
 *
 * Refunds need it because PrestaShop changes the order afterwards: a cancelled line (action 0) is
 * reduced or deleted in `order_detail`, and the order totals are lowered, BEFORE
 * `actionProductCancel` runs (T1 finding). The line refund and the remainder of a later
 * cancellation are therefore computed from what was sent, not from the live order.
 */
final class SentPurchase
{
    /**
     * @param array<int, SentLine> $lines keyed by id_order_detail
     */
    public function __construct(
        public readonly string $currency,
        public readonly float $totalTaxIncl,
        public readonly array $lines,
    ) {
    }

    public static function fromOrder(PurchaseOrder $order): self
    {
        $lines = [];
        foreach ($order->lines as $line) {
            if ($line->idOrderDetail <= 0) {
                continue;
            }
            $lines[$line->idOrderDetail] = new SentLine(
                $line->idOrderDetail,
                $line->productId,
                $line->variantId,
                $line->reference !== '' ? $line->reference : (string) $line->productId,
                $line->quantity,
                $line->unitPriceTaxIncl,
            );
        }

        return new self($order->currency, $order->totalPaidTaxIncl, $lines);
    }

    public function line(int $idOrderDetail): ?SentLine
    {
        return $this->lines[$idOrderDetail] ?? null;
    }

    public function encode(): string
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[] = [
                'idOrderDetail' => $line->idOrderDetail,
                'productId' => $line->productId,
                'variantId' => $line->variantId,
                'sku' => $line->sku,
                'quantity' => $line->quantity,
                'unitPriceTaxIncl' => $line->unitPriceTaxIncl,
            ];
        }

        return (string) json_encode(
            ['currency' => $this->currency, 'totalTaxIncl' => $this->totalTaxIncl, 'lines' => $lines],
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /** Null when the column is empty (an order claimed before 0.1.0) or unreadable. */
    public static function decode(?string $json): ?self
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['currency'] ?? null) || !is_numeric($data['totalTaxIncl'] ?? null)) {
            return null;
        }

        $lines = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (!is_array($line) || !is_numeric($line['idOrderDetail'] ?? null) || (int) $line['idOrderDetail'] <= 0) {
                continue;
            }
            $id = (int) $line['idOrderDetail'];
            $lines[$id] = new SentLine(
                $id,
                is_numeric($line['productId'] ?? null) ? (int) $line['productId'] : 0,
                is_numeric($line['variantId'] ?? null) ? (int) $line['variantId'] : 0,
                is_scalar($line['sku'] ?? null) ? (string) $line['sku'] : '',
                is_numeric($line['quantity'] ?? null) ? (int) $line['quantity'] : 0,
                is_numeric($line['unitPriceTaxIncl'] ?? null) ? (float) $line['unitPriceTaxIncl'] : 0.0,
            );
        }

        return new self($data['currency'], (float) $data['totalTaxIncl'], $lines);
    }
}
