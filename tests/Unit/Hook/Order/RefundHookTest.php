<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Hook\Order;

use Axitrace\PrestaShop\Adapter\HttpResponse;
use Axitrace\PrestaShop\Admin\DeliveryLog;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Hook\Order\RefundHook;
use Axitrace\PrestaShop\Purchase\PurchaseLine;
use Axitrace\PrestaShop\Purchase\PurchaseOrder;
use Axitrace\PrestaShop\Purchase\PurchaseSender;
use Axitrace\PrestaShop\Refund\RefundSlip;
use Axitrace\PrestaShop\Refund\SentPurchase;
use Axitrace\PrestaShop\Retry\FailedEventQueue;
use Axitrace\PrestaShop\Retry\RetryRunner;
use Axitrace\PrestaShop\Tests\Unit\Support\EchoTranslator;
use Axitrace\PrestaShop\Tests\Unit\Support\FailingStatementDb;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHttpTransport;
use Axitrace\PrestaShop\Tests\Unit\Support\Fixtures;
use Axitrace\PrestaShop\Tests\Unit\Support\PurchasePipeline;
use Axitrace\PrestaShop\Tests\Unit\Support\ThrowingDb;
use PHPUnit\Framework\TestCase;

/**
 * Every refund path, replayed from the T1 captures of both full-tier tags where a capture exists:
 * credit slip (8.x without the slip object, 9.x with it), line cancel (action 0, priced from what
 * the purchase carried), actions 1/2/3 ignored, Refunded/Canceled status without a slip, and the
 * guards (no key, not sent, delivered once).
 */
final class RefundHookTest extends TestCase
{
    private const REFUND_URL = PurchasePipeline::SINK . '/v1/refund';

    private static function pipeline(bool $withSecretKey = true): PurchasePipeline
    {
        $pipeline = new PurchasePipeline();
        if ($withSecretKey) {
            $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        }

        return $pipeline;
    }

    /**
     * Places the orders of the first capture and replays the status hook calls of every capture.
     */
    private static function replayStatus(PurchasePipeline $pipeline, string $tag, string ...$captures): PurchaseOrder
    {
        $orders = Fixtures::orders($tag, $captures[0]);
        foreach ($orders as $order) {
            $pipeline->place($order);
        }
        foreach ($captures as $capture) {
            foreach (Fixtures::statusCalls($tag, $capture) as [$idOrder, $stateId, $paid]) {
                $pipeline->status($idOrder, $stateId, $paid);
            }
        }

        return $orders[0];
    }

    /** Replays actionProductCancel / actionOrderSlipAdd of a capture, with its slips in the reader. */
    private static function replayRefunds(PurchasePipeline $pipeline, string $tag, string $capture): void
    {
        foreach (Fixtures::slips($tag, $capture) as $slip) {
            if ($pipeline->refundReader->slip($slip->idOrder, $slip->idOrderSlip) === null) {
                $pipeline->refundReader->addSlip($slip);
            }
        }
        $pipeline->refundReader->slipCalls = [];

        foreach (Fixtures::refundCalls($tag, $capture) as $call) {
            if ($call[0] === 'slip') {
                $pipeline->refundHook()->onSlipAdded($call[1], $call[2]);
            } else {
                $pipeline->refundHook()->onProductCancel($call[1], $call[2], $call[3], $call[4]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>> the refund bodies posted, in order
     */
    private static function refunds(PurchasePipeline $pipeline): array
    {
        $bodies = [];
        foreach ($pipeline->transport->requests as $request) {
            if ($request['url'] === self::REFUND_URL) {
                $bodies[] = $request['payload'];
            }
        }

        return $bodies;
    }

    /** The COD order of capture a, sent at creation, with its live lines in the refund reader. */
    private static function sentCodOrder(PurchasePipeline $pipeline, string $tag): PurchaseOrder
    {
        $order = self::replayStatus($pipeline, $tag, 'a_cod_order');
        foreach ($order->lines as $line) {
            $pipeline->refundReader->lines[$line->idOrderDetail] = $line;
        }

        return $order;
    }

    public function testCreditSlipIsReadWithoutAnIdOn81AndWithTheSlipObjectOn92(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = self::pipeline();
            $order = self::replayStatus($pipeline, $tag, 'd1_wire_order', 'd2_payment_accepted');
            self::replayRefunds($pipeline, $tag, 'd3_standard_refund');

            $slip = Fixtures::slips($tag, 'd3_standard_refund')[0];
            self::assertSame([[$order->idOrder, $tag === '8.1.7' ? null : $slip->idOrderSlip]], $pipeline->refundReader->slipCalls, $tag);

            $refunds = self::refunds($pipeline);
            self::assertCount(1, $refunds, $tag . ': action 1 ignored, one slip refund');
            self::assertSame(sprintf('prestashop:%d:slip:%d', $order->idOrder, $slip->idOrderSlip), $refunds[0]['refundId']);
            self::assertSame((string) $order->idOrder, $refunds[0]['orderId']);
            self::assertSame(19.12, $refunds[0]['amount']);
            self::assertSame('EUR', $refunds[0]['currency']);
            self::assertSame(PurchasePipeline::PK, $refunds[0]['workspace_public_key']);

            $request = $pipeline->transport->requests[1];
            self::assertSame('Basic ' . base64_encode(PurchasePipeline::SK . ':'), $request['headers']['Authorization']);

            $rows = array_values($pipeline->db->refunds);
            self::assertCount(1, $rows);
            self::assertSame('slip', $rows[0]['kind']);
            self::assertNotNull($rows[0]['sent_at']);
            self::assertSame('partially_refunded', $pipeline->db->orders[$order->idOrder]['refund_state']);
        }
    }

    public function testPartialRefundSlipIncludesShippingAndIgnoresAction2(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = self::pipeline();
            self::replayStatus($pipeline, $tag, 'b1_wire_order', 'b2_wire_payment_accepted');
            $calls = Fixtures::refundCalls($tag, 'c_partial_refund_credit_slip');
            self::assertSame(2, $calls[0][4], 'the capture starts with actionProductCancel action 2');

            // The partial refund of capture c was made on another order of the store: replay it
            // against the order sent here.
            $order = Fixtures::orders($tag, 'b1_wire_order')[0];
            $slip = Fixtures::slips($tag, 'c_partial_refund_credit_slip')[0];
            $pipeline->refundReader->addSlip(new RefundSlip($slip->idOrderSlip, $order->idOrder, $slip->totalProductsTaxIncl, $slip->totalShippingTaxIncl, $slip->createdAt, $slip->lines));
            $hook = $pipeline->refundHook();
            $hook->onProductCancel($order->idOrder, $calls[0][2], $calls[0][3], 2);
            $hook->onSlipAdded($order->idOrder, $calls[1][2]);

            $refunds = self::refunds($pipeline);
            self::assertCount(1, $refunds, $tag);
            self::assertSame(24.12, $refunds[0]['amount'], $tag . ': 19.12 + 5.00 shipping');
        }
    }

    public function testSlipInASecondCurrencyIsSentInTheOrderCurrency(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = self::pipeline();
            self::replayStatus($pipeline, $tag, 'h1_foreign_currency_order', 'h2_payment_accepted');
            self::replayRefunds($pipeline, $tag, 'h3_partial_refund_credit_slip');

            $refunds = self::refunds($pipeline);
            self::assertCount(1, $refunds, $tag);
            self::assertSame('USD', $refunds[0]['currency']);
            self::assertSame(26.4, $refunds[0]['amount'], $tag . ': 21.40331 + 5.00 USD, slip amounts used as they are');
        }
    }

    public function testSlipFollowedByRefundedStateIsOneRefund(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = self::pipeline();
            $order = self::replayStatus($pipeline, $tag, 'd1_wire_order', 'd2_payment_accepted');
            self::replayRefunds($pipeline, $tag, 'd3_standard_refund');
            foreach (Fixtures::statusCalls($tag, 'j_refunded_state') as [$idOrder, $stateId, $paid]) {
                self::assertSame($order->idOrder, $idOrder);
                $pipeline->status($idOrder, $stateId, $paid);
            }

            self::assertCount(1, self::refunds($pipeline), $tag . ': the Refunded state is suppressed by the slip');
            self::assertCount(2, $pipeline->transport->requests, $tag . ': purchase + slip refund');
        }
    }

    public function testCodOrderCanceledAfterItWasSentIsOneCancellation(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = self::pipeline();
            $order = self::replayStatus($pipeline, $tag, 'a_cod_order', 'i_cod_canceled_state');

            $refunds = self::refunds($pipeline);
            self::assertCount(1, $refunds, $tag);
            self::assertSame(sprintf('prestashop:%d:state:6', $order->idOrder), $refunds[0]['refundId']);
            self::assertTrue($refunds[0]['isCancellation']);
            self::assertSame(57.14, $refunds[0]['amount']);
            self::assertSame([
                ['sku' => 'demo_1', 'externalId' => 'prestashop:1', 'quantity' => 2, 'amount' => 38.24],
                ['sku' => 'demo_11', 'externalId' => 'prestashop:6', 'quantity' => 1, 'amount' => 11.9],
            ], $refunds[0]['lines']);
            self::assertSame('cancelled', $pipeline->db->orders[$order->idOrder]['refund_state']);

            // Canceled again (OrderHistory), then Refunded: nothing more.
            $pipeline->status($order->idOrder, 6, 0);
            $pipeline->status($order->idOrder, 7, 0);
            self::assertCount(1, self::refunds($pipeline), $tag);
        }
    }

    public function testRefundedStateWithoutSlipIsTheWholeOrder(): void
    {
        $pipeline = self::pipeline();
        $order = self::replayStatus($pipeline, '9.2.0', 'k_paid_at_creation');
        $pipeline->status($order->idOrder, 7, 0);

        $refunds = self::refunds($pipeline);
        self::assertCount(1, $refunds);
        self::assertFalse($refunds[0]['isCancellation']);
        self::assertSame($order->totalPaidTaxIncl, $refunds[0]['amount']);
        self::assertSame('refunded', $pipeline->db->orders[$order->idOrder]['refund_state']);
    }

    public function testAStatusRefundWithoutAnyKnownTotalIsNotSentAndTheOrderIsNotMarked(): void
    {
        $pipeline = self::pipeline();
        $order = self::replayStatus($pipeline, '9.2.0', 'k_paid_at_creation');
        $pipeline->db->orders[$order->idOrder]['purchase_snapshot'] = null;
        unset($pipeline->reader->orders[$order->idOrder]);

        $pipeline->status($order->idOrder, 7, 0);

        self::assertSame([], self::refunds($pipeline));
        self::assertNull($pipeline->db->orders[$order->idOrder]['refund_state'] ?? null, 'never marked refunded without a send');
        self::assertSame(RefundHook::FAILURE_CODE, $pipeline->lastFailure()['code'] ?? null);
        self::assertStringContainsString('refunded amount is unknown', $pipeline->lastFailure()['message']);
    }

    public function testAStatusRefundFallingBackToTheCurrentOrderTotalSaysSo(): void
    {
        $pipeline = self::pipeline();
        $order = self::replayStatus($pipeline, '9.2.0', 'k_paid_at_creation');
        $pipeline->db->orders[$order->idOrder]['purchase_snapshot'] = null;

        $pipeline->status($order->idOrder, 7, 0);

        self::assertSame($order->totalPaidTaxIncl, self::refunds($pipeline)[0]['amount'] ?? null);
        self::assertStringContainsString('computed from the current order total', implode("\n", $pipeline->logger->errors));
    }

    public function testARefundWithoutACurrencyIsNeverSent(): void
    {
        $pipeline = self::pipeline();
        $order = self::replayStatus($pipeline, '9.2.0', 'k_paid_at_creation');
        $pipeline->db->orders[$order->idOrder]['purchase_snapshot'] = null;
        $pipeline->reader->orders[$order->idOrder] = new PurchaseOrder(...['currency' => ''] + get_object_vars($order));

        $pipeline->status($order->idOrder, 7, 0);

        self::assertSame([], self::refunds($pipeline));
        self::assertSame([], $pipeline->db->refunds, 'nothing reserved');
        self::assertNull($pipeline->db->orders[$order->idOrder]['refund_state'] ?? null);
        self::assertStringContainsString('the currency is unknown', $pipeline->lastFailure()['message'] ?? '');
    }

    public function testAQueueWriteFailureGivesTheReservationBackAndIsRecorded(): void
    {
        foreach (['retry' => new HttpResponse(503), 'key rejected' => new HttpResponse(401), 'refused' => new HttpResponse(400)] as $case => $answer) {
            $pipeline = self::pipeline();
            $order = self::sentCodOrder($pipeline, '8.1.7');
            $pipeline->transport->willAnswer($answer);
            $db = new FailingStatementDb($pipeline->db, 'INSERT INTO `ps_axitrace_failed_event`');

            $pipeline->refundHook($db)->onStateChange($pipeline->db->orders[$order->idOrder], 6);

            self::assertSame(1, $db->failures, $case);
            self::assertSame([], $pipeline->db->failedEvents, $case);
            self::assertSame([], $pipeline->db->refunds, $case . ': released, so a later trigger can report it');
            self::assertSame(RefundHook::FAILURE_QUEUE_WRITE, $pipeline->lastFailure()['code'] ?? null, $case);
        }
    }

    public function testTheCurrentOrderTotalFallbackIsRecordedAndDrivesTheRefundState(): void
    {
        $pipeline = self::pipeline();
        $order = self::sentCodOrder($pipeline, '8.1.7');
        $pipeline->db->orders[$order->idOrder]['purchase_snapshot'] = null;
        $pipeline->refundReader->addSlip(new RefundSlip(91, $order->idOrder, $order->totalPaidTaxIncl, 0.0, new \DateTimeImmutable('2026-11-04T10:00:00+00:00'), []));

        $pipeline->refundHook()->onSlipAdded($order->idOrder, 91);

        self::assertCount(1, self::refunds($pipeline));
        self::assertSame('refunded', $pipeline->db->orders[$order->idOrder]['refund_state'], 'the whole current total was refunded');
        self::assertSame(RefundHook::FAILURE_BASE_FALLBACK, $pipeline->lastFailure()['code'] ?? null);
        self::assertStringContainsString(sprintf('current order total %.2f', $order->totalPaidTaxIncl), (string) $pipeline->lastFailure()['message']);
    }

    public function testOtherStatesOfASentOrderAreNotRefunds(): void
    {
        $pipeline = self::pipeline();
        $order = self::replayStatus($pipeline, '8.1.7', 'k_paid_at_creation');
        foreach ([3, 4, 5, 8] as $state) {
            $pipeline->status($order->idOrder, $state, $state === 8 ? 0 : 1);
        }

        self::assertSame([], self::refunds($pipeline));
    }

    public function testProductCancelActions1To3AreIgnored(): void
    {
        $pipeline = self::pipeline();
        $order = self::sentCodOrder($pipeline, '8.1.7');
        $line = $order->lines[1];
        foreach ([1, 2, 3, 4, -1] as $action) {
            $pipeline->refundHook()->onProductCancel($order->idOrder, $line->idOrderDetail, 1, $action);
        }

        self::assertSame([], self::refunds($pipeline));
        self::assertSame([], $pipeline->db->refunds);
    }

    public function testLineCancelIsPricedFromTheSentPurchaseAfterPrestaShopDeletedTheLine(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = self::pipeline();
            $order = self::sentCodOrder($pipeline, $tag);
            $mug = $order->lines[1];

            // What the purchase carried was recorded when it was claimed.
            $sent = SentPurchase::decode((string) $pipeline->db->orders[$order->idOrder]['purchase_snapshot']);
            self::assertNotNull($sent);
            self::assertSame(57.14, $sent->totalTaxIncl);
            self::assertSame(11.9, $sent->line($mug->idOrderDetail)?->unitPriceTaxIncl);

            // T1 finding: the fully cancelled line is already deleted when the hook runs.
            unset($pipeline->refundReader->lines[$mug->idOrderDetail]);
            $pipeline->refundHook()->onProductCancel($order->idOrder, $mug->idOrderDetail, 1, RefundHook::ACTION_CANCEL_LINE);

            $refunds = self::refunds($pipeline);
            self::assertCount(1, $refunds, $tag);
            self::assertSame(sprintf('prestashop:%d:line:%d:1', $order->idOrder, $mug->idOrderDetail), $refunds[0]['refundId']);
            self::assertSame(11.9, $refunds[0]['amount']);
            self::assertFalse($refunds[0]['isCancellation']);
            self::assertSame([['sku' => 'demo_11', 'externalId' => 'prestashop:6', 'quantity' => 1, 'amount' => 11.9]], $refunds[0]['lines']);

            // The same hook again (a second module, a replayed request): nothing more.
            $pipeline->refundHook()->onProductCancel($order->idOrder, $mug->idOrderDetail, 1, RefundHook::ACTION_CANCEL_LINE);
            self::assertCount(1, self::refunds($pipeline), $tag);
        }
    }

    public function testOneUnitCancelledTwiceIsTwoLineRefunds(): void
    {
        $pipeline = self::pipeline();
        $order = self::sentCodOrder($pipeline, '9.2.0');
        $shirt = $order->lines[0];
        self::assertSame(2, $shirt->quantity);

        $pipeline->refundReader->lines[$shirt->idOrderDetail] = new PurchaseLine($shirt->productId, $shirt->variantId, $shirt->reference, $shirt->name, 1, $shirt->unitPriceTaxIncl, $shirt->unitPriceTaxExcl, idOrderDetail: $shirt->idOrderDetail);
        $pipeline->refundHook()->onProductCancel($order->idOrder, $shirt->idOrderDetail, 1, 0);
        unset($pipeline->refundReader->lines[$shirt->idOrderDetail]);
        $pipeline->refundHook()->onProductCancel($order->idOrder, $shirt->idOrderDetail, 1, 0);

        $refunds = self::refunds($pipeline);
        self::assertSame([
            sprintf('prestashop:%d:line:%d:1', $order->idOrder, $shirt->idOrderDetail),
            sprintf('prestashop:%d:line:%d:2', $order->idOrder, $shirt->idOrderDetail),
        ], array_column($refunds, 'refundId'));
        self::assertSame([19.12, 19.12], array_column($refunds, 'amount'));
    }

    /**
     * Cancelling every line moves the order to Canceled (T1): the status refund reports only what
     * the line refunds left (the shipping), so the refunds add up to the purchase, never more.
     */
    public function testCancellingEveryLineThenCanceledNeverCountsTwice(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = self::pipeline();
            $order = self::sentCodOrder($pipeline, $tag);
            foreach ($order->lines as $line) {
                unset($pipeline->refundReader->lines[$line->idOrderDetail]);
                $pipeline->refundHook()->onProductCancel($order->idOrder, $line->idOrderDetail, $line->quantity, 0);
            }
            $pipeline->reader->add(Fixtures::with($order, ['lines' => [], 'totalPaidTaxIncl' => 7.0]));
            $pipeline->status($order->idOrder, 6, 0);

            $refunds = self::refunds($pipeline);
            self::assertSame([38.24, 11.9, 7.0], array_column($refunds, 'amount'), $tag);
            self::assertTrue($refunds[2]['isCancellation']);
            self::assertSame([], $refunds[2]['lines']);
            self::assertEqualsWithDelta($order->totalPaidTaxIncl, array_sum(array_column($refunds, 'amount')), 0.001);
        }
    }

    public function testNothingIsReportedForAnOrderThatWasNeverSent(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            // e: bank wire awaiting payment, one line cancelled (action 0), no purchase sent.
            $pipeline = self::pipeline();
            self::replayStatus($pipeline, $tag, 'e1_wire_order_awaiting');
            self::replayRefunds($pipeline, $tag, 'e2_cancel_not_shipped_line');
            $order = Fixtures::orders($tag, 'e1_wire_order_awaiting')[0];
            $pipeline->status($order->idOrder, 6, 0);

            self::assertSame([], $pipeline->transport->requests, $tag);
            self::assertSame([], $pipeline->db->refunds, $tag);
        }
    }

    public function testWithoutSecretKeyNoCostsAndNoRefunds(): void
    {
        $pipeline = self::pipeline(false);
        $order = self::replayStatus($pipeline, '8.1.7', 'a_cod_order', 'i_cod_canceled_state');

        self::assertCount(1, $pipeline->transport->requests);
        $purchase = $pipeline->transport->requests[0];
        self::assertArrayNotHasKey('Authorization', $purchase['headers']);
        foreach ($purchase['payload']['data']['products'] as $product) {
            self::assertArrayNotHasKey('unitCost', $product);
        }
        self::assertSame([], self::refunds($pipeline));
        self::assertSame([], $pipeline->db->refunds);
        self::assertStringContainsString(sprintf('refund of order %d not reported: no secret key', $order->idOrder), implode("\n", $pipeline->logger->infos));
    }

    public function testWithSecretKeyThePurchaseCarriesCosts(): void
    {
        $pipeline = self::pipeline();
        self::replayStatus($pipeline, '9.2.0', 'h1_foreign_currency_order', 'h2_payment_accepted');

        $products = $pipeline->transport->requests[0]['payload']['data']['products'];
        self::assertSame(['amount' => 6.1456, 'currency' => 'USD'], $products[0]['unitCost']);
        self::assertSame(['amount' => 11.1942, 'currency' => 'USD'], $products[1]['unitCost']);
    }

    public function testDisabledShopReportsNothing(): void
    {
        $pipeline = self::pipeline();
        $order = self::sentCodOrder($pipeline, '8.1.7');
        $pipeline->store->set(ModuleConfig::ENABLED, '0', 1, 1);
        $pipeline->status($order->idOrder, 6, 0);

        self::assertSame([], self::refunds($pipeline));
    }

    public function testUndeliveredRefundIsQueuedAndDeliveredByTheRetryRun(): void
    {
        $pipeline = self::pipeline();
        $order = self::sentCodOrder($pipeline, '8.1.7');
        $pipeline->transport->willAnswer(FakeHttpTransport::timeout());
        $pipeline->status($order->idOrder, 6, 0);

        $queued = array_values($pipeline->db->failedEvents);
        self::assertCount(1, $queued);
        self::assertSame('refund', $queued[0]['endpoint']);
        self::assertSame(sprintf('prestashop:%d:state:6', $order->idOrder), $queued[0]['event_id']);
        self::assertStringNotContainsString('sk_live_', (string) $queued[0]['payload']);
        self::assertNull(array_values($pipeline->db->refunds)[0]['sent_at']);

        $pipeline->clock->now = $pipeline->clock->now->modify('+10 minutes');
        $report = $pipeline->runner()->run(RetryRunner::CRON_BATCH, 60.0);

        self::assertSame(1, $report->delivered);
        self::assertSame([], $pipeline->db->failedEvents);
        self::assertNotNull(array_values($pipeline->db->refunds)[0]['sent_at']);
        $refunds = self::refunds($pipeline);
        self::assertCount(2, $refunds, 'first attempt + retry');
        self::assertSame($refunds[0]['refundId'], $refunds[1]['refundId'], 'same refund id: AxiTrace deduplicates');
    }

    public function testRejectedSecretKeyParksTheRefundUntilRetryNow(): void
    {
        $pipeline = self::pipeline();
        $order = self::sentCodOrder($pipeline, '8.1.7');
        $pipeline->transport->willAnswer(new HttpResponse(401, [], 'Invalid secret key for this workspace'));
        $pipeline->status($order->idOrder, 6, 0);

        $rows = array_values($pipeline->db->failedEvents);
        self::assertCount(1, $rows, 'parked, not dropped');
        self::assertSame(FailedEventQueue::STATUS_PARKED, $rows[0]['status']);
        self::assertSame('refund', $rows[0]['endpoint']);
        self::assertCount(1, $pipeline->db->refunds, 'the reservation is kept: the refund is pending, not forgotten');
        self::assertNull(array_values($pipeline->db->refunds)[0]['sent_at']);
        self::assertSame(PurchaseSender::FAILURE_SECRET_KEY_REJECTED, $pipeline->lastFailure()['code'] ?? null);
        self::assertStringNotContainsString(PurchasePipeline::SK, (string) json_encode($pipeline->store->values));
        self::assertStringNotContainsString(PurchasePipeline::SK, (string) json_encode($pipeline->db->failedEvents));

        // Key fixed, "Retry now": delivered with the same refund id, ledger marked sent.
        $pipeline->transport->willAnswer(new HttpResponse(202));
        $report = (new DeliveryLog($pipeline->queue(), $pipeline->lazyTrigger(), new EchoTranslator(), $pipeline->clock))->retryNow();
        self::assertSame(1, $report?->delivered);
        self::assertSame([], $pipeline->db->failedEvents);
        self::assertNotNull(array_values($pipeline->db->refunds)[0]['sent_at']);
        $refunds = self::refunds($pipeline);
        self::assertSame($refunds[0]['refundId'], $refunds[count($refunds) - 1]['refundId']);
        self::assertNotContains(PurchaseSender::FAILURE_SECRET_KEY_REJECTED, array_column($pipeline->failures()->all(ShopScope::shop(1, 1)), 'code'), 'a keyed success clears the rejected key');
    }

    public function testRefusedRefundIsDeadInTheDeliveryLog(): void
    {
        $pipeline = self::pipeline();
        $order = self::sentCodOrder($pipeline, '8.1.7');
        $pipeline->transport->willAnswer(new HttpResponse(400, [], 'currency must be an ISO 4217 code'));
        $pipeline->status($order->idOrder, 6, 0);

        $rows = array_values($pipeline->db->failedEvents);
        self::assertCount(1, $rows);
        self::assertSame('dead', $rows[0]['status']);
        self::assertSame([], $pipeline->db->refunds);
        self::assertSame(PurchaseSender::FAILURE_PAYLOAD_REJECTED, $pipeline->lastFailure()['code'] ?? null);
    }

    public function testAFailingDatabaseNeverPropagates(): void
    {
        $pipeline = self::pipeline();
        $hook = $pipeline->refundHook(new ThrowingDb());

        $hook->onSlipAdded(30, null);
        $hook->onProductCancel(30, 5, 1, 0);
        $hook->onStateChange(['id_order' => 30, 'id_shop' => 1, 'id_shop_group' => 1], 6);

        self::assertNotSame([], $pipeline->logger->errors);
        self::assertSame([], $pipeline->transport->requests);
    }

    public function testAMissingSlipIsAVisibleFailure(): void
    {
        $pipeline = self::pipeline();
        $order = self::sentCodOrder($pipeline, '8.1.7');
        $pipeline->refundHook()->onSlipAdded($order->idOrder, null);

        self::assertSame(RefundHook::FAILURE_CODE, $pipeline->lastFailure()['code'] ?? null);
        self::assertSame([], self::refunds($pipeline));
    }
}
