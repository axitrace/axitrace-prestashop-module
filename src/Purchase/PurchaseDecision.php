<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Purchase;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Output of {@see PurchaseEligibility}. The value is stored as `axitrace_order.trigger`. */
enum PurchaseDecision: string
{
    /** The new state is marked paid: send now. */
    case SendPaid = 'paid';

    /** Cash on delivery module, order not failed or cancelled: send now. */
    case SendCod = 'cod';

    /** Cancelled or refunded before anything was sent: record that the order was never a sale. */
    case Void = 'void';

    /** Nothing to do (awaiting payment, payment error, already sent). */
    case None = 'none';

    public function isSend(): bool
    {
        return $this === self::SendPaid || $this === self::SendCod;
    }
}
