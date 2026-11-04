<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\OrderReaderInterface;
use Axitrace\PrestaShop\Purchase\OrderStateIds;
use Axitrace\PrestaShop\Purchase\PurchaseOrder;

final class FakeOrderReader implements OrderReaderInterface
{
    /** @var array<int, PurchaseOrder> */
    public array $orders = [];

    public int $reads = 0;

    /** Thrown by read() when set, once more than $readFailureAfter reads were made (a database failure). */
    public ?\Throwable $readFailure = null;

    public int $readFailureAfter = 0;

    public function __construct(private readonly OrderStateIds $states = new OrderStateIds(8, 6, 7), PurchaseOrder ...$orders)
    {
        foreach ($orders as $order) {
            $this->orders[$order->idOrder] = $order;
        }
    }

    public function add(PurchaseOrder $order): void
    {
        $this->orders[$order->idOrder] = $order;
    }

    public function read(int $idOrder): ?PurchaseOrder
    {
        ++$this->reads;
        if ($this->readFailure !== null && $this->reads > $this->readFailureAfter) {
            throw $this->readFailure;
        }

        return $this->orders[$idOrder] ?? null;
    }

    public function stateIds(int $idShopGroup, int $idShop): OrderStateIds
    {
        return $this->states;
    }
}
