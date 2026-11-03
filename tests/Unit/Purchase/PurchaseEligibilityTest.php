<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Purchase;

use Axitrace\PrestaShop\Purchase\OrderStateIds;
use Axitrace\PrestaShop\Purchase\PurchaseDecision;
use Axitrace\PrestaShop\Purchase\PurchaseEligibility;
use Axitrace\PrestaShop\Tests\Unit\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PurchaseEligibilityTest extends TestCase
{
    private const COD = ['ps_cashondelivery'];

    /**
     * Plan T8 acceptance table, with the state flags captured on 8.1.7 and 9.2.0 (identical).
     *
     * @return iterable<string, array{0: string, 1: int, 2: string, 3: bool, 4: list<string>, 5: PurchaseDecision}>
     */
    public static function cases(): iterable
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            yield "$tag COD creation" => [$tag, 13, 'ps_cashondelivery', false, self::COD, PurchaseDecision::SendCod];
            yield "$tag bank wire awaiting" => [$tag, 10, 'ps_wirepayment', false, self::COD, PurchaseDecision::None];
            yield "$tag check awaiting" => [$tag, 1, 'ps_checkpayment', false, self::COD, PurchaseDecision::None];
            yield "$tag Payment accepted" => [$tag, 2, 'ps_wirepayment', false, self::COD, PurchaseDecision::SendPaid];
            yield "$tag Payment accepted twice" => [$tag, 2, 'ps_wirepayment', true, self::COD, PurchaseDecision::None];
            yield "$tag Payment error" => [$tag, 8, 'ps_wirepayment', false, self::COD, PurchaseDecision::None];
            yield "$tag Canceled before send" => [$tag, 6, 'ps_wirepayment', false, self::COD, PurchaseDecision::Void];
            yield "$tag Refunded before send" => [$tag, 7, 'ps_wirepayment', false, self::COD, PurchaseDecision::Void];
            yield "$tag Remote payment accepted" => [$tag, 11, 'ps_checkout', false, self::COD, PurchaseDecision::SendPaid];
            yield "$tag COD module not in list" => [$tag, 13, 'ps_cashondelivery', false, [], PurchaseDecision::None];
            yield "$tag third-party COD module in list" => [$tag, 13, 'codfee', false, ['ps_cashondelivery', 'codfee'], PurchaseDecision::SendCod];
            yield "$tag COD canceled before send" => [$tag, 6, 'ps_cashondelivery', false, self::COD, PurchaseDecision::Void];
            yield "$tag COD payment error" => [$tag, 8, 'ps_cashondelivery', false, self::COD, PurchaseDecision::None];
            // paid, never logable: "On backorder (paid)" is logable=0 paid=1.
            yield "$tag On backorder (paid)" => [$tag, 9, 'ps_wirepayment', false, self::COD, PurchaseDecision::SendPaid];
            yield "$tag On backorder (not paid)" => [$tag, 12, 'ps_wirepayment', false, self::COD, PurchaseDecision::None];
            yield "$tag Processing in progress" => [$tag, 3, 'ps_wirepayment', false, self::COD, PurchaseDecision::SendPaid];
            yield "$tag Shipped" => [$tag, 4, 'ps_wirepayment', false, self::COD, PurchaseDecision::SendPaid];
            yield "$tag Delivered" => [$tag, 5, 'ps_wirepayment', false, self::COD, PurchaseDecision::SendPaid];
            // A COD order moved to Processing after the send at creation: the claim stops it.
            yield "$tag COD to Processing after send" => [$tag, 3, 'ps_cashondelivery', true, self::COD, PurchaseDecision::None];
        }
        // ps_checkout states (9.2.0 only): awaiting/authorised/partial are paid=0, never a send.
        foreach ([14, 15, 16, 17, 18] as $state) {
            yield "9.2.0 ps_checkout state $state" => ['9.2.0', $state, 'ps_checkout', false, self::COD, PurchaseDecision::None];
        }
    }

    /**
     * @param list<string> $codModules
     */
    #[DataProvider('cases')]
    public function testDecision(string $tag, int $stateId, string $module, bool $claimed, array $codModules, PurchaseDecision $expected): void
    {
        $paid = Fixtures::states($tag)[$stateId]['paid'];

        $decision = (new PurchaseEligibility())->decide($stateId, $paid, $module, $claimed, $codModules, Fixtures::stateIds($tag));

        self::assertSame($expected, $decision);
    }

    public function testRuleUsesPaidNotLogable(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $states = Fixtures::states($tag);
            self::assertTrue($states[9]['paid'], $tag);
            self::assertFalse($states[9]['logable'], $tag);
        }
    }

    public function testUnknownStateIdsNeverVoid(): void
    {
        $decision = (new PurchaseEligibility())->decide(6, false, 'ps_wirepayment', false, self::COD, new OrderStateIds(0, 0, 0));

        self::assertSame(PurchaseDecision::None, $decision);
    }

    /**
     * Replays every status hook call of the full-tier captures through the state machine with the
     * claim bookkeeping, per order: each order is sent at most once, at the state the plan says.
     */
    public function testCaptureReplayGivesOneSendPerOrderAtTheRightState(): void
    {
        $sequences = [
            // Order life cycles across captures (same order ids in one tag).
            ['b1_wire_order', 'b2_wire_payment_accepted'],
            ['f1_cod_order', 'f2_to_preparation', 'f4_same_state_again_history', 'f5_same_state_again_history'],
            ['a_cod_order', 'i_cod_canceled_state'],
            ['d1_wire_order', 'd2_payment_accepted', 'j_refunded_state'],
            ['h1_foreign_currency_order', 'h2_payment_accepted'],
            ['e1_wire_order_awaiting'],
            ['g_split_two_carriers'],
            ['k_paid_at_creation'],
        ];
        $expected = [
            'b1_wire_order' => [2],
            'f1_cod_order' => [13],
            'a_cod_order' => [13],
            'd1_wire_order' => [2],
            'h1_foreign_currency_order' => [2],
            'e1_wire_order_awaiting' => [],
            'g_split_two_carriers' => [],
            'k_paid_at_creation' => [2],
        ];
        foreach (Fixtures::FULL_TIER as $tag) {
            $states = Fixtures::states($tag);
            foreach ($sequences as $sequence) {
                $modules = [];
                foreach (Fixtures::orders($tag, $sequence[0]) as $order) {
                    $modules[$order->idOrder] = $order->paymentModule;
                }
                $claimed = [];
                $sends = [];
                foreach ($sequence as $capture) {
                    foreach (Fixtures::statusCalls($tag, $capture) as [$idOrder, $stateId, $rawPaid]) {
                        // The hook's paid flag is "1" (string) or 1 (int) depending on the call.
                        self::assertSame($states[$stateId]['paid'], (int) $rawPaid === 1, "$tag $capture state $stateId");
                        $decision = (new PurchaseEligibility())->decide($stateId, (int) $rawPaid === 1, $modules[$idOrder], isset($claimed[$idOrder]), self::COD, Fixtures::stateIds($tag));
                        if ($decision->isSend()) {
                            $claimed[$idOrder] = true;
                            $sends[] = $stateId;
                        }
                    }
                }
                self::assertSame($expected[$sequence[0]], $sends, "$tag " . implode(' > ', $sequence));
            }
        }
    }
}
