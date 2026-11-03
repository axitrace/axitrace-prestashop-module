<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Gdpr;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** The orders and carts of one GDPR subject: the keys of everything the module stored about them. */
final class CustomerRecords
{
    /**
     * @param list<int> $orderIds
     * @param list<int> $cartIds
     */
    public function __construct(
        public readonly array $orderIds,
        public readonly array $cartIds,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->orderIds === [] && $this->cartIds === [];
    }
}
