<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Hook\Order;

use Axitrace\PrestaShop\Adapter\HttpResponse;
use Axitrace\PrestaShop\Config\DeliveryHealth;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\EventId\Ga4TransactionEventId;
use Axitrace\PrestaShop\Hook\Order\OrderStatusHook;
use Axitrace\PrestaShop\Hook\Order\StatusChange;
use Axitrace\PrestaShop\Purchase\PurchaseLedger;
use Axitrace\PrestaShop\Purchase\PurchaseOrder;
use Axitrace\PrestaShop\Purchase\PurchaseSender;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;
use Axitrace\PrestaShop\Snapshot\SnapshotBuilder;
use Axitrace\PrestaShop\Tests\Unit\Support\FailingStatementDb;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHttpTransport;
use Axitrace\PrestaShop\Tests\Unit\Support\Fixtures;
use Axitrace\PrestaShop\Tests\Unit\Support\PurchasePipeline;
use Axitrace\PrestaShop\Tests\Unit\Support\StaleOrderReadDb;
use Axitrace\PrestaShop\Tests\Unit\Support\ThrowingDb;
use PHPUnit\Framework\TestCase;

final class OrderStatusHookTest extends TestCase
{
    /**
     * Places the orders of the first capture (actionValidateOrder) and replays the status hook
     * calls of every capture of the sequence, exactly as captured on the tag.
     */
    private function replay(PurchasePipeline $pipeline, string $tag, string ...$captures): void
    {
        foreach (Fixtures::orders($tag, $captures[0]) as $order) {
            $pipeline->place($order, ['visitorId' => 'visitor-' . $order->idOrder, 'clientIp' => '203.0.113.7']);
        }
        foreach ($captures as $capture) {
            foreach (Fixtures::statusCalls($tag, $capture) as [$idOrder, $stateId, $paid]) {
                $pipeline->status($idOrder, $stateId, $paid);
            }
        }
    }

    public function testCodOrderIsSentOnceAtCreation(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = new PurchasePipeline();
            $this->replay($pipeline, $tag, 'a_cod_order');

            self::assertCount(1, $pipeline->transport->requests, $tag);
            $request = $pipeline->transport->requests[0];
            self::assertSame(PurchasePipeline::SINK . '/v1/plugin/pixel', $request['url']);
            $order = Fixtures::orders($tag, 'a_cod_order')[0];
            self::assertSame((string) $order->idOrder, $request['payload']['orderId']);
            self::assertSame(Ga4TransactionEventId::fromTransaction(PurchasePipeline::PK, (string) $order->idOrder), $request['payload']['eventSalt']);
            self::assertSame('cod', $request['payload']['data']['trigger']);
            self::assertSame(13, $request['payload']['data']['orderStateId']);
            self::assertSame('visitor-' . $order->idOrder, $request['payload']['userId']);
            $row = $pipeline->db->orders[$order->idOrder];
            self::assertNotNull($row['purchase_claimed_at']);
            self::assertNotNull($row['purchase_sent_at']);
            self::assertSame('cod', $row['trigger']);
        }
    }

    /**
     * T1 f-sequence: COD order -> Processing in progress (paid=1) -> the same state twice more
     * through OrderHistory (hooks fire again, old == new).
     */
    public function testCodOrderMovedToPaidStatesAndRepeatedStatesIsNeverSentTwice(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = new PurchasePipeline();
            $this->replay($pipeline, $tag, 'f1_cod_order', 'f2_to_preparation', 'f4_same_state_again_history', 'f5_same_state_again_history');

            self::assertCount(1, $pipeline->transport->requests, $tag);
            self::assertSame('cod', $pipeline->transport->requests[0]['payload']['data']['trigger']);
        }
    }

    public function testBankWireIsSentWhenThePaymentIsAccepted(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = new PurchasePipeline();
            $this->replay($pipeline, $tag, 'b1_wire_order');
            self::assertCount(0, $pipeline->transport->requests, "$tag: awaiting bank wire is not a sale");

            foreach (Fixtures::statusCalls($tag, 'b2_wire_payment_accepted') as [$idOrder, $stateId, $paid]) {
                $pipeline->status($idOrder, $stateId, $paid);
            }

            self::assertCount(1, $pipeline->transport->requests, $tag);
            $payload = $pipeline->transport->requests[0]['payload'];
            self::assertSame('paid', $payload['data']['trigger']);
            self::assertSame(2, $payload['data']['orderStateId']);
            // The order date, not the payment date (A22): 22:41:00 Paris = 21:41:00 UTC on 8.1.7.
            $order = Fixtures::orders($tag, 'b1_wire_order')[0];
            self::assertSame((new \DateTimeImmutable($order->dateAdd, new \DateTimeZone('Europe/Paris')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), $payload['timestamp']);
        }
    }

    public function testPaidAtCreationRefundedLaterIsOneSend(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = new PurchasePipeline();
            $this->replay($pipeline, $tag, 'k_paid_at_creation');
            self::assertCount(1, $pipeline->transport->requests, $tag);
            self::assertSame('paid', $pipeline->transport->requests[0]['payload']['data']['trigger']);

            $pipeline = new PurchasePipeline();
            $this->replay($pipeline, $tag, 'd1_wire_order', 'd2_payment_accepted', 'j_refunded_state');
            self::assertCount(1, $pipeline->transport->requests, "$tag: refunded after the send is T10's refund, not a purchase");
        }
    }

    public function testSplitCartOrdersAreSentSeparately(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = new PurchasePipeline();
            $this->replay($pipeline, $tag, 'g_split_two_carriers');
            self::assertCount(0, $pipeline->transport->requests);

            foreach (Fixtures::orders($tag, 'g_split_two_carriers') as $order) {
                $pipeline->status($order->idOrder, 2, '1');
                $pipeline->status($order->idOrder, 2, '1');
            }

            self::assertCount(2, $pipeline->transport->requests, $tag);
            [$a, $b] = array_column($pipeline->transport->requests, 'payload');
            self::assertNotSame($a['eventSalt'], $b['eventSalt']);
            self::assertSame($a['data']['orderReference'], $b['data']['orderReference']);
        }
    }

    public function testPaymentErrorChangesNothingCanceledVoidsAndARevivedPaidOrderIsSent(): void
    {
        $pipeline = new PurchasePipeline();
        $order = Fixtures::orders('9.2.0', 'b1_wire_order')[0];
        $pipeline->place($order);

        $pipeline->status($order->idOrder, 8, '0');
        self::assertFalse(PurchaseLedger::isVoid($pipeline->db->orders[$order->idOrder]));

        $pipeline->status($order->idOrder, 6, '0');
        self::assertTrue(PurchaseLedger::isVoid($pipeline->db->orders[$order->idOrder]));
        self::assertCount(0, $pipeline->transport->requests);

        $pipeline->status($order->idOrder, 2, '1');
        self::assertCount(1, $pipeline->transport->requests);
    }

    public function testCodCanceledAfterTheSendIsNoSecondPurchase(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $pipeline = new PurchasePipeline();
            $this->replay($pipeline, $tag, 'a_cod_order', 'i_cod_canceled_state');

            self::assertCount(1, $pipeline->transport->requests, $tag);
        }
    }

    public function testOrderMissedByActionValidateOrderGetsItsRowFromTheCartSnapshot(): void
    {
        $pipeline = new PurchasePipeline();
        $order = Fixtures::orders('8.1.7', 'k_paid_at_creation')[0];
        $pipeline->reader->add($order);
        $snapshot = ['visitorId' => 'cart-visitor', 'consent' => 'granted'];
        (new CartSnapshotRepository($pipeline->db))->save($order->idCart, 1, $snapshot, SnapshotBuilder::hash($snapshot), $pipeline->clock->now());

        $pipeline->status($order->idOrder, 2, 1);

        self::assertArrayHasKey($order->idOrder, $pipeline->db->orders);
        self::assertSame(Ga4TransactionEventId::fromTransaction(PurchasePipeline::PK, '30'), $pipeline->db->orders[30]['event_id']);
        self::assertSame('cart-visitor', $pipeline->transport->requests[0]['payload']['userId']);
        self::assertSame('granted', $pipeline->transport->requests[0]['payload']['data']['consent']);
    }

    public function testDisabledShopSendsNothing(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->store->set(ModuleConfig::ENABLED, '0', 1, 1);
        $this->replay($pipeline, '8.1.7', 'k_paid_at_creation');

        self::assertCount(0, $pipeline->transport->requests);
        self::assertFalse(PurchaseLedger::isClaimed($pipeline->db->orders[30]));
    }

    public function testExcludedCustomerGroupVoidsTheOrder(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->store->set(ModuleConfig::EXCLUDED_GROUPS, '3,4', 1, 1);
        $this->replay($pipeline, '8.1.7', 'k_paid_at_creation');

        self::assertCount(0, $pipeline->transport->requests);
        self::assertTrue(PurchaseLedger::isVoid($pipeline->db->orders[30]));
        self::assertSame(['order 30 not sent: the customer group is excluded from tracking.'], $pipeline->logger->infos);
    }

    public function testConsentModeAllIsFailClosed(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->store->setGlobal(ModuleConfig::CONSENT_MODE, 'all');
        $order = Fixtures::orders('9.2.0', 'k_paid_at_creation')[0];
        $pipeline->place($order, ['visitorId' => 'no-decision']);
        $pipeline->status($order->idOrder, 2, 1);
        self::assertCount(0, $pipeline->transport->requests);
        self::assertTrue(PurchaseLedger::isVoid($pipeline->db->orders[$order->idOrder]));

        $pipeline = new PurchasePipeline();
        $pipeline->store->setGlobal(ModuleConfig::CONSENT_MODE, 'all');
        $pipeline->place($order, ['consent' => 'granted']);
        $pipeline->status($order->idOrder, 2, 1);
        self::assertCount(1, $pipeline->transport->requests);
    }

    public function testAMissingSnapshotUnderConsentModeAllIsARecordedFailureNotAConsentDecision(): void
    {
        $order = Fixtures::orders('9.2.0', 'k_paid_at_creation')[0];

        foreach (['missing' => null, 'unreadable' => '{"consent":"gran'] as $case => $raw) {
            $pipeline = new PurchasePipeline();
            $pipeline->store->setGlobal(ModuleConfig::CONSENT_MODE, 'all');
            $pipeline->place($order);
            $pipeline->db->orders[$order->idOrder]['snapshot'] = $raw;

            $pipeline->status($order->idOrder, 2, 1);

            self::assertCount(0, $pipeline->transport->requests, $case . ': the consent decision itself is unchanged');
            self::assertTrue(PurchaseLedger::isVoid($pipeline->db->orders[$order->idOrder]), $case);
            self::assertSame($case === 'missing' ? OrderStatusHook::FAILURE_SNAPSHOT_MISSING : OrderStatusHook::FAILURE_SNAPSHOT_UNREADABLE, $pipeline->lastFailure()['code'] ?? null, $case);
            self::assertStringContainsString($case === 'missing' ? 'no attribution snapshot was recorded' : 'attribution snapshot is stored but unreadable', (string) $pipeline->lastFailure()['message'], $case);
            self::assertSame([], $pipeline->logger->infos, $case);
        }

        // An explicit "denied" is the buyer's choice: info only, nothing recorded.
        $pipeline = new PurchasePipeline();
        $pipeline->store->setGlobal(ModuleConfig::CONSENT_MODE, 'all');
        $pipeline->place($order, ['consent' => 'denied']);
        $pipeline->status($order->idOrder, 2, 1);
        self::assertCount(0, $pipeline->transport->requests);
        self::assertNull($pipeline->lastFailure());
        self::assertStringContainsString('no consent recorded for server-side tracking', $pipeline->logger->infos[0] ?? '');
    }

    public function testAnUnreadableSnapshotIsRecordedOutsideConsentModeToo(): void
    {
        $pipeline = new PurchasePipeline();
        $order = Fixtures::orders('9.2.0', 'k_paid_at_creation')[0];
        $pipeline->place($order);
        $pipeline->db->orders[$order->idOrder]['snapshot'] = '{"visitorId":"v';

        $pipeline->status($order->idOrder, 2, 1);

        self::assertCount(1, $pipeline->transport->requests, 'consent mode off: sent, without attribution');
        self::assertSame([OrderStatusHook::FAILURE_SNAPSHOT_UNREADABLE], array_column($pipeline->failures()->all(ShopScope::shop(1, 1)), 'code'));
    }

    public function testAClaimThatCannotBeGivenBackSaysThePurchaseMustBeResentManually(): void
    {
        $pipeline = new PurchasePipeline();
        $order = Fixtures::orders('8.1.7', 'b1_wire_order')[0];
        $pipeline->place($order);
        $pipeline->transport->willAnswer(new HttpResponse(503));

        $pipeline->hook(new FailingStatementDb($pipeline->db, 'INSERT INTO `ps_axitrace_failed_event`', 'SET `purchase_claimed_at` = NULL'))->handle(new StatusChange($order->idOrder, 2, true));

        self::assertTrue(PurchaseLedger::isClaimed($pipeline->db->orders[$order->idOrder]));
        $failure = $pipeline->lastFailure();
        self::assertSame(PurchaseSender::FAILURE_QUEUE_WRITE, $failure['code'] ?? null);
        self::assertStringContainsString('could not be queued for a retry', $failure['message']);
        self::assertStringContainsString('the purchase is NOT sent: resend it manually', $failure['message']);
    }

    public function testAQueueWriteFailureReleasesTheClaimAndTheNextStatusChangeResends(): void
    {
        $pipeline = new PurchasePipeline();
        $order = Fixtures::orders('8.1.7', 'b1_wire_order')[0];
        $pipeline->place($order);
        $pipeline->transport->willAnswer(new HttpResponse(503), new HttpResponse(202));

        $pipeline->hook(new FailingStatementDb($pipeline->db, 'INSERT INTO `ps_axitrace_failed_event`'))->handle(new StatusChange($order->idOrder, 2, true));

        $row = $pipeline->db->orders[$order->idOrder];
        self::assertFalse(PurchaseLedger::isClaimed($row), 'released: neither delivered nor queued');
        self::assertSame([], $pipeline->db->failedEvents);
        self::assertSame(PurchaseSender::FAILURE_QUEUE_WRITE, $pipeline->lastFailure()['code'] ?? null);
        self::assertStringContainsString('could not be queued for a retry', $pipeline->lastFailure()['message']);

        $pipeline->status($order->idOrder, 2, 1);

        self::assertCount(2, $pipeline->transport->requests);
        self::assertSame($pipeline->transport->requests[0]['payload']['eventSalt'], $pipeline->transport->requests[1]['payload']['eventSalt'], 'same eventSalt: AxiTrace deduplicates');
        self::assertNotNull($pipeline->db->orders[$order->idOrder]['purchase_sent_at']);
        self::assertNotContains(PurchaseSender::FAILURE_QUEUE_WRITE, array_column($pipeline->failures()->all(ShopScope::shop(1, 1)), 'code'), 'cleared by the delivered purchase');
    }

    public function testAnOrderWithoutCurrencyIsNotSentAndTheClaimIsGivenBack(): void
    {
        $pipeline = new PurchasePipeline();
        $order = Fixtures::orders('8.1.7', 'b1_wire_order')[0];
        $pipeline->place(new PurchaseOrder(...['currency' => ''] + get_object_vars($order)));

        $pipeline->status($order->idOrder, 2, 1);

        self::assertCount(0, $pipeline->transport->requests);
        self::assertFalse(PurchaseLedger::isClaimed($pipeline->db->orders[$order->idOrder]));
        self::assertStringContainsString('is not an ISO 4217 code', $pipeline->lastFailure()['message'] ?? '');

        $pipeline->reader->add($order);
        $pipeline->status($order->idOrder, 2, 1);
        self::assertCount(1, $pipeline->transport->requests, 'the next status change sends it once the currency is readable');
        self::assertSame($order->currency, $pipeline->transport->requests[0]['payload']['data']['value']['currency'] ?? null);
    }

    public function testUnreachableIngestionKeepsTheClaimSoNoLaterStateSendsAgain(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->transport->willAnswer(FakeHttpTransport::timeout());
        $order = Fixtures::orders('8.1.7', 'b1_wire_order')[0];
        $pipeline->place($order);

        $pipeline->status($order->idOrder, 2, '1');
        $pipeline->status($order->idOrder, 3, '1');

        self::assertCount(1, $pipeline->transport->requests);
        $row = $pipeline->db->orders[$order->idOrder];
        self::assertTrue(PurchaseLedger::isClaimed($row));
        self::assertNull($row['purchase_sent_at'] ?? null);
        self::assertSame(PurchaseSender::FAILURE_DELIVERY, $pipeline->lastFailure()['code'] ?? null);
    }

    public function testRejectedSecretKeyResendsOnceWithoutKeyAndShowsTheBanner(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        $pipeline->transport->willAnswer(new HttpResponse(401, [], 'Invalid secret key for this workspace'), new HttpResponse(202));
        $this->replay($pipeline, '8.1.7', 'k_paid_at_creation');

        self::assertCount(2, $pipeline->transport->requests);
        self::assertArrayHasKey('Authorization', $pipeline->transport->requests[0]['headers']);
        self::assertArrayNotHasKey('Authorization', $pipeline->transport->requests[1]['headers']);
        self::assertSame($pipeline->transport->requests[0]['payload']['eventSalt'], $pipeline->transport->requests[1]['payload']['eventSalt']);
        self::assertNotNull($pipeline->db->orders[30]['purchase_sent_at']);
        self::assertSame(PurchaseSender::FAILURE_SECRET_KEY_REJECTED, $pipeline->lastFailure()['code'] ?? null);
        self::assertStringNotContainsString(PurchasePipeline::SK, implode("\n", $pipeline->logger->errors));
        self::assertSame(DeliveryHealth::COST_KEY_NONE, $pipeline->health()->costKeyState(ShopScope::shop(1, 1)));
    }

    public function testUnknownPublicKeyIsRecordedForTheBanner(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->transport->willAnswer(new HttpResponse(401, [], 'Invalid workspace_public_key'));
        $this->replay($pipeline, '9.2.0', 'k_paid_at_creation');

        self::assertCount(1, $pipeline->transport->requests);
        self::assertSame(PurchaseSender::FAILURE_PUBLIC_KEY_REJECTED, $pipeline->lastFailure()['code'] ?? null);
        self::assertNull($pipeline->db->orders[6]['purchase_sent_at'] ?? null);
    }

    public function testRefusedPayloadIsRecordedAndNotRetried(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->transport->willAnswer(new HttpResponse(400, [], 'eventSalt required'));
        $this->replay($pipeline, '9.2.0', 'k_paid_at_creation');
        $pipeline->status(6, 3, '1');

        self::assertCount(1, $pipeline->transport->requests);
        $failure = $pipeline->lastFailure();
        self::assertNotNull($failure);
        self::assertSame(PurchaseSender::FAILURE_PAYLOAD_REJECTED, $failure['code']);
        self::assertStringContainsString('HTTP 400: eventSalt required', $failure['message']);
    }

    public function testSuccessRecordsHealth(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        $this->replay($pipeline, '8.1.7', 'k_paid_at_creation');

        $scope = ShopScope::shop(1, 1);
        self::assertSame('2026-11-03T22:00:00+00:00', $pipeline->health()->lastSuccess($scope));
        self::assertSame(DeliveryHealth::COST_KEY_ACCEPTED, $pipeline->health()->costKeyState($scope));
    }

    public function testAThrowingDatabaseNeverPropagates(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->reader->add(Fixtures::orders('8.1.7', 'k_paid_at_creation')[0]);

        $pipeline->hook(new ThrowingDb())->handle(new StatusChange(30, 2, true));

        self::assertCount(0, $pipeline->transport->requests);
        self::assertSame('order_status', $pipeline->failures()->last(ShopScope::context())['code'] ?? null);
        self::assertStringContainsString('handling the status change of order 30 to state 2 failed (RuntimeException: Database query failed: Lost connection)', $pipeline->logger->errors[0]);
    }

    public function testAThrowingTransportNeverPropagatesAndKeepsTheClaim(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->transport->willAnswer(new \LogicException('unexpected'));
        $this->replay($pipeline, '8.1.7', 'k_paid_at_creation');

        self::assertCount(1, $pipeline->transport->requests);
        self::assertTrue(PurchaseLedger::isClaimed($pipeline->db->orders[30]));
        self::assertSame(PurchaseSender::FAILURE_DELIVERY, $pipeline->lastFailure()['code'] ?? null);
    }

    public function testConcurrentHooksSendOnce(): void
    {
        $pipeline = new PurchasePipeline();
        $order = Fixtures::orders('9.2.0', 'k_paid_at_creation')[0];
        $pipeline->place($order);

        // The second request read the NEW row before the first one claimed it: only the claim
        // winner sends, the loser's UPDATE changes no row.
        $stale = new StaleOrderReadDb($pipeline->db);
        $pipeline->hook()->handle(new StatusChange($order->idOrder, 2, true));
        $pipeline->hook($stale)->handle(new StatusChange($order->idOrder, 3, true));

        self::assertCount(1, $pipeline->transport->requests);
        self::assertSame(1, $pipeline->db->noOpUpdates, 'the losing claim');
    }

    public function testOrderOfAnotherShopUsesThatShopsKey(): void
    {
        $pipeline = new PurchasePipeline();
        $otherKey = 'pk_live_' . str_repeat('b', 32);
        $pipeline->store->set(ModuleConfig::PUBLIC_KEY, $otherKey, 1, 2);
        $order = Fixtures::with(Fixtures::orders('8.1.7', 'k_paid_at_creation')[0], ['idShop' => 2]);
        $pipeline->place($order);

        $pipeline->status(30, 2, 1);

        self::assertSame($otherKey, $pipeline->transport->requests[0]['payload']['workspace_public_key']);
    }

    public function testUnknownOrderAndInvalidIdsAreIgnored(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->status(404, 2, 1);
        $pipeline->status(0, 2, 1);

        self::assertCount(0, $pipeline->transport->requests);
        self::assertSame([], $pipeline->logger->errors);
    }

    public function testHookValuesOfEveryVersionAreCast(): void
    {
        self::assertTrue(StatusChange::fromHookValues('23', '2', '1')->newStatePaid);
        self::assertTrue(StatusChange::fromHookValues(23, 2, 1)->newStatePaid);
        self::assertFalse(StatusChange::fromHookValues(23, 10, '0')->newStatePaid);
        self::assertFalse(StatusChange::fromHookValues(23, 10, null)->newStatePaid);
        self::assertSame(0, StatusChange::fromHookValues(null, 'x', 1)->idOrder);
    }
}
