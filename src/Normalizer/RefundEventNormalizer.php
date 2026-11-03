<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Normalizer;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Purchase\PurchaseLine;
use Axitrace\PrestaShop\Refund\RefundSlip;
use Axitrace\PrestaShop\Refund\SentLine;

/**
 * Builds the `POST /v1/refund` bodies, the AxiTrace refund contract shared with the Shopware
 * plugin (shopware-plugin/src/Normalizer/RefundEventNormalizer.php):
 *
 *   { orderId, refundId, refundedAt, amount, currency, isCancellation,
 *     lines: [{ sku, externalId, quantity, amount }] }
 *
 * `orderId` is `(string) id_order`, the identifier the purchase was sent under (A4). Every amount
 * is GROSS and in the ORDER currency, the money that went back to the shopper (A16); the server
 * applies the workspace's value basis. Line `sku` / `externalId` are those of the purchase's
 * `products[]` (sku = reference, else the product id; externalId = `prestashop:<id_product>`).
 *
 * Three sources (A23), each with a deterministic `refundId`, so a repeated hook or a retry can
 * never count twice (AxiTrace deduplicates on it):
 *  - credit slip (partial refund, standard refund, return): `prestashop:<id_order>:slip:<id>`,
 *    amount = `total_products_tax_incl + total_shipping_tax_incl` of the slip, rounded to cents;
 *  - line cancelled on an unpaid order (actionProductCancel action 0):
 *    `prestashop:<id_order>:line:<id_order_detail>:<qty>` where qty is the line's cumulative
 *    cancelled quantity after this cancel (1 then 2 when one unit is cancelled twice), amount =
 *    gross unit price as sent x cancelled quantity;
 *  - order set to Refunded or Canceled without any credit slip: `prestashop:<id_order>:state:<id>`,
 *    amount = what was sent minus every refund already reported; `isCancellation` for Canceled.
 *
 * Pure: no I/O.
 */
final class RefundEventNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function slip(RefundSlip $slip, string $currency): array
    {
        $lines = [];
        foreach ($slip->lines as $line) {
            $lines[] = self::line($line->productId, $line->reference, $line->quantity, $line->amountTaxIncl);
        }

        return self::body(
            $slip->idOrder,
            self::slipRefundId($slip->idOrder, $slip->idOrderSlip),
            $slip->createdAt,
            $slip->totalProductsTaxIncl + $slip->totalShippingTaxIncl,
            $currency,
            false,
            $lines,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function lineCancel(int $idOrder, SentLine $line, int $cancelQuantity, int $cumulativeQuantity, string $currency, \DateTimeImmutable $now): array
    {
        $amount = $line->unitPriceTaxIncl * $cancelQuantity;

        return self::body(
            $idOrder,
            self::lineRefundId($idOrder, $line->idOrderDetail, $cumulativeQuantity),
            $now,
            $amount,
            $currency,
            false,
            [self::line($line->productId, $line->sku, $cancelQuantity, $amount)],
        );
    }

    /**
     * @param list<PurchaseLine> $remainingLines the order lines still on the order
     *
     * @return array<string, mixed>
     */
    public function state(int $idOrder, int $stateId, bool $isCancellation, float $amount, string $currency, array $remainingLines, \DateTimeImmutable $now): array
    {
        $lines = [];
        foreach ($remainingLines as $line) {
            if ($line->quantity > 0) {
                $lines[] = self::line($line->productId, $line->reference, $line->quantity, $line->unitPriceTaxIncl * $line->quantity);
            }
        }

        return self::body($idOrder, self::stateRefundId($idOrder, $stateId), $now, $amount, $currency, $isCancellation, $lines);
    }

    public static function slipRefundId(int $idOrder, int $idOrderSlip): string
    {
        return sprintf('%s%d:slip:%d', OrderEventNormalizer::EXTERNAL_ID_PREFIX, $idOrder, $idOrderSlip);
    }

    public static function lineRefundId(int $idOrder, int $idOrderDetail, int $cumulativeQuantity): string
    {
        return sprintf('%s%d:line:%d:%d', OrderEventNormalizer::EXTERNAL_ID_PREFIX, $idOrder, $idOrderDetail, $cumulativeQuantity);
    }

    public static function stateRefundId(int $idOrder, int $stateId): string
    {
        return sprintf('%s%d:state:%d', OrderEventNormalizer::EXTERNAL_ID_PREFIX, $idOrder, $stateId);
    }

    /**
     * @return array{sku: string, externalId: string, quantity: int, amount: float}
     */
    private static function line(int $productId, string $reference, int $quantity, float $amount): array
    {
        return [
            'sku' => $reference !== '' ? $reference : (string) $productId,
            'externalId' => OrderEventNormalizer::externalIdFor($productId),
            'quantity' => max(0, $quantity),
            'amount' => self::money($amount),
        ];
    }

    /**
     * @param list<array{sku: string, externalId: string, quantity: int, amount: float}> $lines
     *
     * @return array<string, mixed>
     */
    private static function body(int $idOrder, string $refundId, \DateTimeImmutable $refundedAt, float $amount, string $currency, bool $isCancellation, array $lines): array
    {
        return [
            'orderId' => (string) $idOrder,
            'refundId' => $refundId,
            'refundedAt' => $refundedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'amount' => self::money($amount),
            'currency' => strtoupper($currency),
            'isCancellation' => $isCancellation,
            'lines' => $lines,
        ];
    }

    /** Rounded to cents, never negative (the refund endpoint refuses negative amounts). */
    private static function money(float $amount): float
    {
        return is_finite($amount) ? max(0.0, round($amount, 2)) : 0.0;
    }
}
