<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Normalizer;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\LoggerInterface;
use Axitrace\PrestaShop\Consent\ConsentGate;
use Axitrace\PrestaShop\Purchase\PurchaseLine;
use Axitrace\PrestaShop\Purchase\PurchaseOrder;

/**
 * Builds the server purchase for `POST /v1/plugin/pixel` and the browser purchase rendered on the
 * order confirmation page. Pure mapper: no I/O.
 *
 * Keys are those of the AxiTrace Shopware plugin normalizer (A38), so event-worker needs no
 * PrestaShop-specific code:
 *   { event, eventSalt, event_id, transactionId, orderId, workspace_public_key, source, timestamp,
 *     ip, userAgent, pluginVersion, sdkVersion, userId, sessionId, billingCity, billingCountry,
 *     billingZip, billingState,
 *     data: { client: {email, phone, firstName, lastName}, products: [...], revenue, value,
 *             orderNumber, tax, shipping, valueBasis, paymentInfo: {method},
 *             fbp?, fbc?, ttp?, rdt_uuid?, obref?, <click ids>?, _ga?, ga_session_id?, consent?, url?,
 *             orderReference, cartId, psVersion, phpVersion, trigger, orderStateId } }
 *   products[]: { productId, externalId, variantId, sku, name, quantity, price, currency, brand?,
 *                 category?, unitCost?: { amount, currency } }
 *
 * The PrestaShop-only keys travel inside `data`: the ingestion API keeps `data` as it is and reads
 * only the known top-level keys.
 *
 * - transactionId / orderId / orderNumber = (string) id_order (A4: `reference` is shared by split
 *   orders); the GA4 event id is derived from the same transaction id.
 * - timestamp = `orders.date_add` (stored in PS_TIMEZONE) in UTC RFC 3339 (A22): AxiTrace keeps one
 *   row per event id within +-24 h of the order time, and a bank wire paid days later must stay
 *   attributed to the visit that led to the order.
 * - `revenue`/`value` follow the configured value basis; `tax` and `shipping` are always gross.
 * - Browser ids, click ids, consent, IP and user agent come from the attribution snapshot captured
 *   in the buyer's own request (A24); absent keys are omitted, never guessed.
 * - `unitCost` (server purchase only): the NET cost per unit in the order currency
 *   ({@see PurchaseCostResolver}); a line without a cost has no key. Costs are always put on the
 *   server payload; {@see \Axitrace\PrestaShop\Purchase\PurchaseSender} strips them whenever the
 *   shop has no usable secret key at the moment of sending (also for a retry), because AxiTrace
 *   only keeps costs from an authenticated request.
 */
final class OrderEventNormalizer
{
    public const EVENT = 'transaction.charge';
    public const SOURCE = 'prestashop';
    public const SDK_VERSION = 'prestashop-1.0';
    public const EXTERNAL_ID_PREFIX = 'prestashop:';

    /** Snapshot key => data key, browser ids captured at checkout. */
    private const SNAPSHOT_DATA_KEYS = [
        'fbp' => 'fbp',
        'fbc' => 'fbc',
        'ttp' => 'ttp',
        'rdtUuid' => 'rdt_uuid',
        'obref' => 'obref',
        'ga' => '_ga',
        'gaSession' => 'ga_session_id',
        'landingUrl' => 'url',
    ];

    public function __construct(
        private readonly PlatformInfo $platform,
        private readonly ClockInterface $clock,
        private readonly PurchaseCostResolver $costs,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function externalIdFor(int $productId): string
    {
        return $productId > 0 ? self::EXTERNAL_ID_PREFIX . $productId : '';
    }

    /**
     * The server-side purchase.
     *
     * @param array<string, mixed>|null $snapshot the order's attribution snapshot
     *
     * @return array<string, mixed>
     */
    public function normalize(
        PurchaseOrder $order,
        string $eventId,
        string $publicKey,
        ConversionValueBasis $basis,
        ?array $snapshot,
        string $trigger,
        int $orderStateId,
    ): array {
        $snapshot ??= [];
        $orderId = (string) $order->idOrder;

        $data = $this->orderData($order, $basis, $snapshot, true);
        $data['trigger'] = $trigger;
        $data['orderStateId'] = $orderStateId;

        return [
            'event' => self::EVENT,
            'eventSalt' => $eventId,
            'event_id' => $eventId,
            'transactionId' => $orderId,
            'orderId' => $orderId,
            'workspace_public_key' => $publicKey,
            'source' => self::SOURCE,
            'timestamp' => $this->timestamp($order),
            'ip' => self::string($snapshot, 'clientIp'),
            'userAgent' => self::string($snapshot, 'userAgent'),
            'pluginVersion' => $this->platform->pluginVersion,
            'sdkVersion' => self::SDK_VERSION,
            'userId' => self::string($snapshot, 'visitorId'),
            'sessionId' => self::string($snapshot, 'sessionId'),
            'billingCity' => $order->customer->city,
            'billingCountry' => $order->customer->countryIso,
            'billingZip' => $order->customer->zip,
            'billingState' => $order->customer->stateIso,
            'data' => $data,
        ];
    }

    /**
     * The browser purchase (`transaction.charge` through the SDK on the confirmation page): the same
     * order data and the same event id as the server purchase, so whichever arrives first is kept
     * and is complete. Never with costs (rendered into the page) and never the IP or user agent.
     *
     * @param array<string, mixed>|null $snapshot
     *
     * @return array<string, mixed>
     */
    public function browserPurchase(PurchaseOrder $order, string $eventId, ConversionValueBasis $basis, ?array $snapshot): array
    {
        $params = $this->orderData($order, $basis, $snapshot ?? [], false);
        $params['eventSalt'] = $eventId;
        $params['transactionId'] = (string) $order->idOrder;
        $params['orderId'] = (string) $order->idOrder;
        $params['source'] = self::SOURCE;
        $params['shippingAddress'] = [
            'city' => $order->customer->city,
            'country' => $order->customer->countryIso,
            'state' => $order->customer->stateIso,
            'zip' => $order->customer->zip,
        ];

        return $params;
    }

    /**
     * `orders.date_add` in UTC, RFC 3339 with a Z suffix; the current time if it is unusable. An
     * invalid shop time zone is logged and read as UTC.
     */
    public function timestamp(PurchaseOrder $order): string
    {
        $utc = new \DateTimeZone('UTC');
        try {
            $zone = new \DateTimeZone($order->timezone !== '' ? $order->timezone : 'UTC');
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('order %d: PS_TIMEZONE "%s" is not a valid time zone (%s); the order date is read as UTC. Fix it in International > Localization.', $order->idOrder, mb_substr($order->timezone, 0, 64), $e->getMessage()));
            $zone = $utc;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $order->dateAdd, $zone);
        if ($date === false || (int) $date->format('Y') < 2000) {
            $date = $this->clock->now();
        }

        return $date->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private function orderData(PurchaseOrder $order, ConversionValueBasis $basis, array $snapshot, bool $withCosts): array
    {
        if (preg_match('/^[A-Z]{3}$/', $order->currency) !== 1) {
            // Never a purchase without its currency: AxiTrace would book it in the workspace currency.
            throw new \RuntimeException(sprintf('order %d: the currency "%s" is not an ISO 4217 code, so the purchase is not sent', $order->idOrder, mb_substr($order->currency, 0, 16)));
        }

        $money = ['amount' => self::conversionValue($order, $basis), 'currency' => $order->currency];

        $products = [];
        foreach ($order->lines as $line) {
            $product = $this->product($line, $order->currency, $basis);
            $unitCost = $withCosts ? $this->costs->unitCost($line, $order->conversionRate) : null;
            if ($unitCost !== null) {
                $product['unitCost'] = ['amount' => $unitCost, 'currency' => $order->currency];
            }
            $products[] = $product;
        }

        $data = [
            'client' => [
                'email' => $order->customer->email,
                'phone' => $order->customer->phone,
                'firstName' => $order->customer->firstName,
                'lastName' => $order->customer->lastName,
            ],
            'products' => $products,
            'revenue' => $money,
            'value' => $money,
        ];

        foreach (self::SNAPSHOT_DATA_KEYS as $snapshotKey => $dataKey) {
            $value = self::string($snapshot, $snapshotKey);
            if ($value !== '') {
                $data[$dataKey] = $value;
            }
        }

        $clickIds = $snapshot['clickIds'] ?? null;
        if (is_array($clickIds)) {
            foreach ($clickIds as $key => $value) {
                if (is_string($key) && is_string($value) && trim($value) !== '' && self::isClickIdKey($key)) {
                    $data[$key] = trim($value);
                }
            }
        }

        $consent = $snapshot['consent'] ?? null;
        if ($consent === ConsentGate::DECISION_GRANTED || $consent === ConsentGate::DECISION_DENIED) {
            $data['consent'] = $consent;
        }

        $data['orderNumber'] = (string) $order->idOrder;
        $data['tax'] = self::nonNegative($order->totalPaidTaxIncl - $order->totalPaidTaxExcl);
        $data['shipping'] = self::nonNegative($order->totalShippingTaxIncl);
        $data['valueBasis'] = $basis->value;
        if ($order->paymentModule !== '') {
            $data['paymentInfo'] = ['method' => $order->paymentModule];
        }

        $data['orderReference'] = $order->reference;
        $data['cartId'] = (string) $order->idCart;
        $data['psVersion'] = $this->platform->psVersion;
        $data['phpVersion'] = $this->platform->phpVersion;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function product(PurchaseLine $line, string $currency, ConversionValueBasis $basis): array
    {
        $product = ['productId' => (string) $line->productId];
        $externalId = self::externalIdFor($line->productId);
        if ($externalId !== '') {
            $product['externalId'] = $externalId;
        }
        $product += [
            'variantId' => (string) $line->variantId,
            'sku' => $line->reference !== '' ? $line->reference : (string) $line->productId,
            'name' => $line->name,
            'quantity' => (float) $line->quantity,
            'price' => round($basis->includesTax() ? $line->unitPriceTaxIncl : $line->unitPriceTaxExcl, 2),
            'currency' => $currency,
        ];
        if ($line->brand !== '') {
            $product['brand'] = $line->brand;
        }
        if ($line->category !== '') {
            $product['category'] = $line->category;
        }

        return $product;
    }

    /** Same arithmetic as the Shopware plugin's ConversionValueResolver; never negative. */
    public static function conversionValue(PurchaseOrder $order, ConversionValueBasis $basis): float
    {
        $value = match ($basis) {
            ConversionValueBasis::GrossTotal => $order->totalPaidTaxIncl,
            ConversionValueBasis::GrossExclShipping => $order->totalPaidTaxIncl - $order->totalShippingTaxIncl,
            ConversionValueBasis::NetTotal => $order->totalPaidTaxExcl,
            ConversionValueBasis::NetExclShipping => $order->totalPaidTaxExcl - $order->totalShippingTaxExcl,
        };

        return self::nonNegative($value);
    }

    /**
     * The click ids the Shopware plugin forwards as flat `data` keys. A bare fbclid is not one:
     * Meta's click travels as `fbc` (the snapshot's _fbc cookie).
     */
    private static function isClickIdKey(string $key): bool
    {
        return array_key_exists($key, ClickIdReader::CLICK_IDS);
    }

    private static function nonNegative(float $amount): float
    {
        return is_finite($amount) ? max(0.0, round($amount, 2)) : 0.0;
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function string(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
