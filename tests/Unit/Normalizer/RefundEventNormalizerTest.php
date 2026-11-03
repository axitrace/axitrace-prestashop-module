<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Normalizer;

use Axitrace\PrestaShop\Normalizer\RefundEventNormalizer;
use Axitrace\PrestaShop\Purchase\PurchaseLine;
use Axitrace\PrestaShop\Refund\RefundSlip;
use Axitrace\PrestaShop\Refund\RefundSlipLine;
use Axitrace\PrestaShop\Refund\SentLine;
use Axitrace\PrestaShop\Tests\Unit\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class RefundEventNormalizerTest extends TestCase
{
    /** The keys of the AxiTrace refund contract (Shopware RefundEventNormalizer). */
    private const KEYS = ['orderId', 'refundId', 'refundedAt', 'amount', 'currency', 'isCancellation', 'lines'];

    public function testPartialRefundSlipIsGrossWithShipping(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            // c: 1 x 19.12 refunded plus 5.00 shipping, EUR.
            $slip = Fixtures::slips($tag, 'c_partial_refund_credit_slip')[0];
            $order = Fixtures::capture($tag, 'c_partial_refund_credit_slip')['result']['facts']['order'][0];
            $body = (new RefundEventNormalizer())->slip($slip, 'EUR');

            self::assertSame(self::KEYS, array_keys($body), $tag);
            self::assertSame((string) $order['id_order'], $body['orderId']);
            self::assertSame(sprintf('prestashop:%d:slip:%d', $order['id_order'], $slip->idOrderSlip), $body['refundId']);
            self::assertSame(24.12, $body['amount'], $tag . ': products 19.12 + shipping 5.00');
            self::assertSame('EUR', $body['currency']);
            self::assertFalse($body['isCancellation']);
            self::assertSame([['sku' => 'demo_1', 'externalId' => 'prestashop:1', 'quantity' => 1, 'amount' => 19.12]], $body['lines']);
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $body['refundedAt']);
        }
    }

    public function testSlipInASecondCurrencyStaysInTheOrderCurrency(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            // h3: USD order (rate 1.119420), 1 x 21.40331 USD + 5.00 USD shipping (T1 finding 6).
            $slip = Fixtures::slips($tag, 'h3_partial_refund_credit_slip')[0];
            $body = (new RefundEventNormalizer())->slip($slip, 'USD');

            self::assertSame(26.4, $body['amount'], $tag . ': 21.40331 + 5.00 rounded to cents, never converted');
            self::assertSame('USD', $body['currency']);
            self::assertSame(21.4, $body['lines'][0]['amount']);
        }
    }

    public function testStandardRefundSlipWithoutShipping(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            $slip = Fixtures::slips($tag, 'd3_standard_refund')[0];
            self::assertSame(19.12, (new RefundEventNormalizer())->slip($slip, 'EUR')['amount'], $tag);
        }
    }

    public function testSlipDateIsConvertedToUtc(): void
    {
        $slip = new RefundSlip(4, 30, 10.0, 0.0, new \DateTimeImmutable('2026-11-03 22:41:35', new \DateTimeZone('Europe/Paris')), []);

        self::assertSame('2026-11-03T21:41:35Z', (new RefundEventNormalizer())->slip($slip, 'eur')['refundedAt']);
        self::assertSame('EUR', (new RefundEventNormalizer())->slip($slip, 'eur')['currency']);
    }

    public function testSlipLineWithoutReferenceUsesTheProductIdAsSku(): void
    {
        $slip = new RefundSlip(4, 30, 10.0, 0.0, new \DateTimeImmutable('@0'), [new RefundSlipLine(9, 6, '', 1, 10.0)]);

        self::assertSame(['sku' => '6', 'externalId' => 'prestashop:6', 'quantity' => 1, 'amount' => 10.0], (new RefundEventNormalizer())->slip($slip, 'EUR')['lines'][0]);
    }

    public function testLineCancelIsPricedFromTheSentLine(): void
    {
        $now = new \DateTimeImmutable('2026-11-04T10:00:00Z');
        $body = (new RefundEventNormalizer())->lineCancel(25, new SentLine(43, 6, 0, 'demo_11', 1, 11.9), 1, 1, 'EUR', $now);

        self::assertSame(self::KEYS, array_keys($body));
        self::assertSame('prestashop:25:line:43:1', $body['refundId']);
        self::assertSame(11.9, $body['amount']);
        self::assertFalse($body['isCancellation']);
        self::assertSame([['sku' => 'demo_11', 'externalId' => 'prestashop:6', 'quantity' => 1, 'amount' => 11.9]], $body['lines']);
        self::assertSame('2026-11-04T10:00:00Z', $body['refundedAt']);
    }

    public function testCancellingOneUnitTwiceGivesTwoRefundIds(): void
    {
        $normalizer = new RefundEventNormalizer();
        $line = new SentLine(42, 1, 1, 'demo_1', 2, 19.12);
        $now = new \DateTimeImmutable('@0');

        $first = $normalizer->lineCancel(25, $line, 1, 1, 'EUR', $now);
        $second = $normalizer->lineCancel(25, $line, 1, 2, 'EUR', $now);

        self::assertNotSame($first['refundId'], $second['refundId']);
        self::assertSame(19.12, $second['amount']);
    }

    public function testStateRefundCarriesTheRestAndMarksCancellations(): void
    {
        $normalizer = new RefundEventNormalizer();
        $lines = [new PurchaseLine(1, 1, 'demo_1', 'T-shirt', 2, 19.12, 19.12), new PurchaseLine(6, 0, '', 'Mug', 0, 11.9, 11.9)];
        $now = new \DateTimeImmutable('@0');

        $canceled = $normalizer->state(22, 6, true, 57.14, 'EUR', $lines, $now);
        self::assertSame('prestashop:22:state:6', $canceled['refundId']);
        self::assertTrue($canceled['isCancellation']);
        self::assertSame(57.14, $canceled['amount']);
        self::assertSame([['sku' => 'demo_1', 'externalId' => 'prestashop:1', 'quantity' => 2, 'amount' => 38.24]], $canceled['lines'], 'a line with nothing left is not listed');

        $refunded = $normalizer->state(22, 7, false, 7.0, 'EUR', [], $now);
        self::assertSame('prestashop:22:state:7', $refunded['refundId']);
        self::assertFalse($refunded['isCancellation']);
        self::assertSame([], $refunded['lines']);
    }

    public function testAmountsAreNeverNegative(): void
    {
        $body = (new RefundEventNormalizer())->state(22, 7, false, -3.0, 'EUR', [], new \DateTimeImmutable('@0'));

        self::assertSame(0.0, $body['amount']);
    }
}
