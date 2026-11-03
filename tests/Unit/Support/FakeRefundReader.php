<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\RefundReaderInterface;
use Axitrace\PrestaShop\Purchase\PurchaseLine;
use Axitrace\PrestaShop\Refund\RefundSlip;

/** Credit slips and live order lines as a test sets them up. */
final class FakeRefundReader implements RefundReaderInterface
{
    /** @var array<int, list<RefundSlip>> id_order => slips in id order */
    public array $slips = [];

    /** @var array<int, PurchaseLine> id_order_detail => the line as it is now (absent = deleted) */
    public array $lines = [];

    /** @var list<array{0: int, 1: int|null}> every slip() call: [id_order, id_order_slip] */
    public array $slipCalls = [];

    public function addSlip(RefundSlip $slip): void
    {
        $this->slips[$slip->idOrder][] = $slip;
    }

    public function slip(int $idOrder, ?int $idOrderSlip): ?RefundSlip
    {
        $this->slipCalls[] = [$idOrder, $idOrderSlip];
        $slips = $this->slips[$idOrder] ?? [];
        if ($idOrderSlip === null) {
            return $slips === [] ? null : $slips[count($slips) - 1];
        }
        foreach ($slips as $slip) {
            if ($slip->idOrderSlip === $idOrderSlip) {
                return $slip;
            }
        }

        return null;
    }

    public function slipCount(int $idOrder): int
    {
        return count($this->slips[$idOrder] ?? []);
    }

    public function currentLine(int $idOrderDetail): ?PurchaseLine
    {
        return $this->lines[$idOrderDetail] ?? null;
    }
}
