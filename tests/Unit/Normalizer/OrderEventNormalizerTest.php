<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Normalizer;

use Axitrace\PrestaShop\EventId\Ga4TransactionEventId;
use Axitrace\PrestaShop\Normalizer\ClickIdReader;
use Axitrace\PrestaShop\Http\IngestionClient;
use Axitrace\PrestaShop\Normalizer\ConversionValueBasis;
use Axitrace\PrestaShop\Normalizer\OrderEventNormalizer;
use Axitrace\PrestaShop\Normalizer\PlatformInfo;
use Axitrace\PrestaShop\Normalizer\PurchaseCostResolver;
use Axitrace\PrestaShop\Purchase\PurchaseOrder;
use Axitrace\PrestaShop\Tests\Unit\Support\Fixtures;
use Axitrace\PrestaShop\Tests\Unit\Support\MutableClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderEventNormalizerTest extends TestCase
{
    private const PK = 'pk_live_0123456789abcdef0123456789abcdef';

    /** Keys the PrestaShop payload adds to the Shopware key set (plan T8 payload contract). */
    private const PRESTASHOP_EXTRAS = [
        'data' => ['orderReference', 'cartId', 'psVersion', 'phpVersion', 'trigger', 'orderStateId'],
        'product' => ['variantId'],
    ];

    /** Golden captures: one file per order of the capture. Regenerate with AXITRACE_UPDATE_GOLDEN=1. */
    private const GOLDEN_CAPTURES = ['a_cod_order', 'b1_wire_order', 'g_split_two_carriers', 'h1_foreign_currency_order', 'k_paid_at_creation'];

    private function normalizer(): OrderEventNormalizer
    {
        return new OrderEventNormalizer(new PlatformInfo('0.0.1', '8.1.7', '8.1.34'), new MutableClock(), new PurchaseCostResolver());
    }

    /**
     * A snapshot carrying every key the snapshot builder can write.
     *
     * @return array<string, mixed>
     */
    private static function fullSnapshot(): array
    {
        $clickIds = [];
        foreach (array_keys(ClickIdReader::CLICK_IDS) as $key) {
            $clickIds[$key] = 'click-' . $key;
        }
        $clickIds['fbclid'] = 'IwAR-url-only';

        return [
            'clickIds' => $clickIds,
            'clientIp' => '203.0.113.7',
            'consent' => 'granted',
            'fbc' => 'fb.1.1793742000000.IwAR123',
            'fbp' => 'fb.1.1793742000000.1234567890',
            'ga' => 'GA1.1.123456789.1793742000',
            'gaSession' => 'GS1.1.1793742000.1.1.1793742500.0.0.0',
            'landingUrl' => 'http://localhost:8181/en/order',
            'obref' => 'obref-1',
            'rdtUuid' => '1793742000000.6a0b2c1d-1111-4222-8333-944455556666',
            'sessionId' => 'session-0001',
            'ttp' => 'ttp-cookie-0001',
            'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/130.0 Safari/537.36',
            'visitorId' => 'visitor-0001',
        ];
    }

    private static function order(string $tag, string $capture, int $index = 0): PurchaseOrder
    {
        return Fixtures::orders($tag, $capture)[$index];
    }

    /**
     * @param array<string, mixed>|null $snapshot
     *
     * @return array<string, mixed>
     */
    private function normalize(PurchaseOrder $order, ?array $snapshot, ConversionValueBasis $basis = ConversionValueBasis::GrossTotal): array
    {
        return $this->normalizer()->normalize(
            $order,
            Ga4TransactionEventId::fromTransaction(self::PK, (string) $order->idOrder),
            self::PK,
            $basis,
            $snapshot,
            $order->paymentModule === 'ps_cashondelivery' ? 'cod' : 'paid',
            $order->currentStateId,
        );
    }

    /**
     * @return array<string, array{required: list<string>, all: list<string>}>
     */
    private static function shopwareKeys(): array
    {
        $sections = [];
        foreach (file(__DIR__ . '/../../fixtures/shopware_payload_keys.txt', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            [$section, $key] = [$parts[0], $parts[1]];
            $sections[$section] ??= ['required' => [], 'all' => []];
            $sections[$section]['all'][] = $key;
            if (($parts[2] ?? '') !== 'optional') {
                $sections[$section]['required'][] = $key;
            }
        }

        return $sections;
    }

    public function testKeysAreTheShopwareKeySetPlusThePrestaShopExtras(): void
    {
        $shopware = self::shopwareKeys();
        self::assertSame(['top', 'data', 'client', 'product'], array_keys($shopware));

        foreach (Fixtures::FULL_TIER as $tag) {
            foreach ([self::fullSnapshot(), null] as $snapshot) {
                $payload = $this->normalize(self::order($tag, 'k_paid_at_creation'), $snapshot);
                $actual = [
                    'top' => array_keys($payload),
                    'data' => array_keys($payload['data']),
                    'client' => array_keys($payload['data']['client']),
                    'product' => array_keys($payload['data']['products'][0]),
                ];

                foreach ($shopware as $section => $keys) {
                    $label = "$tag $section " . ($snapshot === null ? 'without snapshot' : 'full snapshot');
                    self::assertSame([], array_values(array_diff($keys['required'], $actual[$section])), "$label: Shopware keys missing");
                    $extras = array_values(array_diff($actual[$section], $keys['all']));
                    sort($extras);
                    $expectedExtras = self::PRESTASHOP_EXTRAS[$section] ?? [];
                    sort($expectedExtras);
                    self::assertSame($expectedExtras, $extras, "$label: keys outside the contract");
                }
                self::assertSame($shopware['top']['all'], $actual['top'], 'top-level keys in the Shopware order');
            }
        }

        // With a full snapshot every optional data key the snapshot can fill is present.
        $data = $this->normalize(self::order('8.1.7', 'k_paid_at_creation'), self::fullSnapshot())['data'];
        foreach (['fbp', 'fbc', 'ttp', 'rdt_uuid', 'obref', '_ga', 'ga_session_id', 'consent', 'url', 'paymentInfo'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
        foreach (array_keys(ClickIdReader::CLICK_IDS) as $key) {
            self::assertSame('click-' . $key, $data[$key]);
        }
        self::assertArrayNotHasKey('fbclid', $data, 'Meta clicks travel as fbc, like Shopware');
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int}>
     */
    public static function goldenOrders(): iterable
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            foreach (self::GOLDEN_CAPTURES as $capture) {
                foreach (Fixtures::orders($tag, $capture) as $index => $order) {
                    yield "$tag $capture #$order->idOrder" => [$tag, $capture, $index];
                }
            }
        }
    }

    #[DataProvider('goldenOrders')]
    public function testGoldenPayload(string $tag, string $capture, int $index): void
    {
        $order = self::order($tag, $capture, $index);
        $json = json_encode($this->normalize($order, self::fullSnapshot()), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION) . "\n";
        $path = sprintf('%s/../../fixtures/golden/%s/%s-%d.json', __DIR__, $tag, $capture, $order->idOrder);

        if (getenv('AXITRACE_UPDATE_GOLDEN') === '1') {
            @mkdir(dirname($path), 0o777, true);
            file_put_contents($path, $json);
        }

        self::assertFileExists($path);
        self::assertSame((string) file_get_contents($path), $json);
    }

    public function testIdsTimestampAndAmountsOfAPaidOrder(): void
    {
        // k_paid_at_creation on 8.1.7: order 30, 2 x 19.12 + 11.90 + 7.00 shipping, Europe/Paris.
        $order = self::order('8.1.7', 'k_paid_at_creation');
        $payload = $this->normalize($order, self::fullSnapshot());

        self::assertSame('transaction.charge', $payload['event']);
        self::assertSame('30', $payload['transactionId']);
        self::assertSame('30', $payload['orderId']);
        self::assertSame('30', $payload['data']['orderNumber']);
        self::assertSame('QDVGHUQCV', $payload['data']['orderReference']);
        self::assertSame('29', $payload['data']['cartId']);
        self::assertSame(Ga4TransactionEventId::fromTransaction(self::PK, '30'), $payload['eventSalt']);
        self::assertSame($payload['eventSalt'], $payload['event_id']);
        self::assertSame('prestashop', $payload['source']);
        self::assertSame('prestashop-1.0', $payload['sdkVersion']);
        // date_add 22:49:33 Europe/Paris (CET) = 21:49:33 UTC, the time the capture's hook ran.
        self::assertSame('2026-11-03 22:49:33', $order->dateAdd);
        self::assertSame('2026-11-03T21:49:33Z', $payload['timestamp']);
        $hookTs = (int) Fixtures::capture('8.1.7', 'k_paid_at_creation')['hooks'][0]['ts'];
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $hookTs), $payload['timestamp']);
        self::assertSame(['amount' => 57.14, 'currency' => 'EUR'], $payload['data']['value']);
        self::assertSame($payload['data']['value'], $payload['data']['revenue']);
        self::assertSame(7.0, $payload['data']['shipping']);
        self::assertSame(0.0, $payload['data']['tax']);
        self::assertSame(['method' => 'ps_wirepayment'], $payload['data']['paymentInfo']);
        self::assertSame('203.0.113.7', $payload['ip']);
        self::assertSame('visitor-0001', $payload['userId']);
        self::assertSame('session-0001', $payload['sessionId']);
        self::assertSame([
            'productId' => '1',
            'externalId' => 'prestashop:1',
            'variantId' => '1',
            'sku' => 'demo_1',
            'name' => 'Hummingbird printed t-shirt - Size : S- Color : White',
            'quantity' => 2.0,
            'price' => 19.12,
            'currency' => 'EUR',
            'brand' => 'Studio Design',
            'category' => 'Men',
            // purchase_supplier_price 5.49 (EUR order, conversion rate 1).
            'unitCost' => ['amount' => 5.49, 'currency' => 'EUR'],
        ], $payload['data']['products'][0]);
        self::assertSame('0', $payload['data']['products'][1]['variantId']);
        self::assertSame(['amount' => 10.0, 'currency' => 'EUR'], $payload['data']['products'][1]['unitCost']);
    }

    public function testSplitOrdersShareTheReferenceButNotTheIds(): void
    {
        foreach (Fixtures::FULL_TIER as $tag) {
            [$first, $second] = Fixtures::orders($tag, 'g_split_two_carriers');
            $a = $this->normalize($first, null);
            $b = $this->normalize($second, null);

            self::assertSame($a['data']['orderReference'], $b['data']['orderReference'], $tag);
            self::assertNotSame($a['transactionId'], $b['transactionId'], $tag);
            self::assertNotSame($a['eventSalt'], $b['eventSalt'], $tag);
        }
    }

    public function testForeignCurrencyOrderUsesTheOrderCurrency(): void
    {
        $payload = $this->normalize(self::order('9.2.0', 'h1_foreign_currency_order'), null);

        self::assertSame('USD', $payload['data']['value']['currency']);
        self::assertSame('USD', $payload['data']['products'][0]['currency']);
        self::assertSame(21.4, $payload['data']['products'][0]['price']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function timestamps(): iterable
    {
        yield 'Paris winter (CET)' => ['2026-11-03 22:49:33', 'Europe/Paris', '2026-11-03T21:49:33Z'];
        yield 'Paris summer (CEST)' => ['2026-07-01 12:00:00', 'Europe/Paris', '2026-07-01T10:00:00Z'];
        yield 'day boundary' => ['2026-11-04 00:30:00', 'Europe/Warsaw', '2026-11-03T23:30:00Z'];
        yield 'west of UTC' => ['2026-11-03 20:00:00', 'America/New_York', '2026-11-04T01:00:00Z'];
        yield 'UTC shop' => ['2026-11-03 22:49:33', 'UTC', '2026-11-03T22:49:33Z'];
        yield 'no timezone configured' => ['2026-11-03 22:49:33', '', '2026-11-03T22:49:33Z'];
        yield 'unknown timezone' => ['2026-11-03 22:49:33', 'Mars/Olympus', '2026-11-03T22:49:33Z'];
        yield 'zero date falls back to now' => ['0000-00-00 00:00:00', 'Europe/Paris', '2026-11-03T12:00:00Z'];
        yield 'garbage falls back to now' => ['yesterday', 'Europe/Paris', '2026-11-03T12:00:00Z'];
    }

    #[DataProvider('timestamps')]
    public function testTimestampIsTheOrderDateInUtc(string $dateAdd, string $timezone, string $expected): void
    {
        $base = self::order('8.1.7', 'k_paid_at_creation');
        $order = new PurchaseOrder($base->idOrder, $base->idShop, $base->idShopGroup, $base->idCart, $base->reference, $base->paymentModule, $base->currency, $base->totalPaidTaxIncl, $base->totalPaidTaxExcl, $base->totalShippingTaxIncl, $base->totalShippingTaxExcl, $dateAdd, $timezone, $base->currentStateId, $base->currentStatePaid, $base->customerDefaultGroupId, $base->customer, $base->lines);

        self::assertSame($expected, $this->normalizer()->timestamp($order));
    }

    /**
     * @return iterable<string, array{0: ConversionValueBasis, 1: float, 2: float}>
     */
    public static function bases(): iterable
    {
        // Order: 100.00 gross / 81.30 net, shipping 12.30 gross / 10.00 net, line 50.00 gross / 40.65 net.
        yield 'gross total' => [ConversionValueBasis::GrossTotal, 100.0, 50.0];
        yield 'gross excl shipping' => [ConversionValueBasis::GrossExclShipping, 87.7, 50.0];
        yield 'net total' => [ConversionValueBasis::NetTotal, 81.3, 40.65];
        yield 'net excl shipping' => [ConversionValueBasis::NetExclShipping, 71.3, 40.65];
    }

    #[DataProvider('bases')]
    public function testValueFollowsTheValueBasisTaxAndShippingStayGross(ConversionValueBasis $basis, float $value, float $price): void
    {
        $base = self::order('8.1.7', 'k_paid_at_creation');
        $line = $base->lines[0];
        $taxed = new \Axitrace\PrestaShop\Purchase\PurchaseLine($line->productId, $line->variantId, $line->reference, $line->name, 2, 50.0, 40.65);
        $order = new PurchaseOrder($base->idOrder, $base->idShop, $base->idShopGroup, $base->idCart, $base->reference, $base->paymentModule, $base->currency, 100.0, 81.3, 12.3, 10.0, $base->dateAdd, $base->timezone, $base->currentStateId, $base->currentStatePaid, $base->customerDefaultGroupId, $base->customer, [$taxed]);

        $payload = $this->normalize($order, null, $basis);

        self::assertSame($value, $payload['data']['value']['amount']);
        self::assertSame($price, $payload['data']['products'][0]['price']);
        self::assertSame(18.7, $payload['data']['tax']);
        self::assertSame(12.3, $payload['data']['shipping']);
        self::assertSame($basis->value, $payload['data']['valueBasis']);
    }

    public function testWithoutSnapshotNothingIsGuessed(): void
    {
        $payload = $this->normalize(self::order('9.2.0', 'b1_wire_order'), null);

        self::assertSame('', $payload['ip']);
        self::assertSame('', $payload['userAgent']);
        self::assertSame('', $payload['userId']);
        foreach (['fbp', 'fbc', 'consent', 'url', 'gclid', '_ga'] as $key) {
            self::assertArrayNotHasKey($key, $payload['data']);
        }
    }

    public function testBrowserPurchaseSharesTheEventIdAndCarriesNoTransportData(): void
    {
        $order = self::order('9.2.0', 'a_cod_order');
        $eventId = Ga4TransactionEventId::fromTransaction(self::PK, (string) $order->idOrder);
        $browser = $this->normalizer()->browserPurchase($order, $eventId, ConversionValueBasis::GrossTotal, self::fullSnapshot());
        $server = $this->normalize($order, self::fullSnapshot());

        self::assertSame($server['eventSalt'], $browser['eventSalt']);
        self::assertSame($server['transactionId'], $browser['transactionId']);
        self::assertSame($server['data']['value'], $browser['value']);
        self::assertSame(IngestionClient::withoutUnitCosts($server)['data']['products'], $browser['products']);
        foreach ($browser['products'] as $product) {
            self::assertArrayNotHasKey('unitCost', $product, 'costs are never rendered into the page');
        }
        self::assertArrayHasKey('unitCost', $server['data']['products'][0]);
        self::assertSame('prestashop', $browser['source']);
        self::assertSame(['city' => 'Warsaw', 'country' => 'PL', 'state' => '', 'zip' => '00-001'], $browser['shippingAddress']);
        foreach (['ip', 'userAgent', 'clientIp', 'workspace_public_key', 'trigger', 'orderStateId'] as $key) {
            self::assertArrayNotHasKey($key, $browser);
        }
        self::assertStringNotContainsString('203.0.113.7', (string) json_encode($browser));
    }
}
