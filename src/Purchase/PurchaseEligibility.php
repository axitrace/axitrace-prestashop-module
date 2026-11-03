<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Purchase;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The purchase state machine of one order, as a pure function (plan: Architecture Decision).
 *
 *   1. Already claimed or sent -> none. (The atomic claim in {@see PurchaseLedger} enforces it
 *      again under concurrency; this check only saves the work.)
 *   2. The new state is marked paid -> send (trigger "paid"). The flag is `paid`, never `logable`:
 *      "On backorder (paid)" is paid=1 but logable=0, and "Processing in progress", "Shipped" and
 *      "Delivered" are paid=1 too.
 *   3. The order's payment module is in the cash on delivery list and the new state is not
 *      Payment error, Canceled or Refunded -> send (trigger "cod"). COD awaiting states are paid=0.
 *   4. Canceled or Refunded before any send -> void. A payment error is not final (the buyer may
 *      pay again), so it changes nothing.
 *   5. Otherwise (bank wire or check awaiting payment, payment error) -> none.
 *
 * A void order that later reaches a paid state is still sent (rule 2): a cancelled order that the
 * merchant revives and gets paid is a sale.
 */
final class PurchaseEligibility
{
    /**
     * @param list<string> $codModules
     */
    public function decide(
        int $newStateId,
        bool $newStatePaid,
        string $orderModule,
        bool $alreadyClaimed,
        array $codModules,
        OrderStateIds $states,
    ): PurchaseDecision {
        if ($alreadyClaimed) {
            return PurchaseDecision::None;
        }

        if ($newStatePaid) {
            return PurchaseDecision::SendPaid;
        }

        $failed = $newStateId > 0 && in_array($newStateId, [$states->error, $states->canceled, $states->refund], true);
        if (!$failed && $orderModule !== '' && in_array($orderModule, $codModules, true)) {
            return PurchaseDecision::SendCod;
        }

        if ($newStateId > 0 && in_array($newStateId, [$states->canceled, $states->refund], true)) {
            return PurchaseDecision::Void;
        }

        return PurchaseDecision::None;
    }
}
