<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Hook\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * One `actionOrderStatusPostUpdate` call: the order and its new state. The `paid` flag comes from
 * the hook's OrderState (string "1" or int 1 depending on the version; cast by the caller).
 */
final class StatusChange
{
    public function __construct(
        public readonly int $idOrder,
        public readonly int $newStateId,
        public readonly bool $newStatePaid,
    ) {
    }

    /** Builds the change from the hook's raw OrderState fields, whatever their PHP type. */
    public static function fromHookValues(mixed $idOrder, mixed $stateId, mixed $paid): self
    {
        return new self(
            is_numeric($idOrder) ? (int) $idOrder : 0,
            is_numeric($stateId) ? (int) $stateId : 0,
            (is_numeric($paid) || is_bool($paid)) && (int) $paid === 1,
        );
    }
}
