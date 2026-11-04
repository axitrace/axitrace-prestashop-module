<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Http;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\HttpTransportException;
use Axitrace\PrestaShop\Adapter\HttpTransportInterface;

/**
 * Posts events to the AxiTrace ingestion API, server to server.
 *
 * Purchases go to `POST <api base>/v1/plugin/pixel` (source "prestashop", eventSalt required).
 * With a secret key the request carries `Authorization: Basic base64(<secret key>:)`, which is what
 * makes AxiTrace keep the per-line `unitCost`; without it AxiTrace strips them.
 *
 * Short timeouts (1 s connect, 2 s total): the call runs inside the order status change of the
 * back office or the payment module, which must never wait for AxiTrace. The status mapping is
 * {@see map()}; a 401 to a request sent WITH a key throws {@see SecretKeyRejectedException}.
 *
 * Nothing that identifies the buyer, and never the key, is put into an error message.
 */
final class IngestionClient
{
    public const PIXEL_PATH = '/v1/plugin/pixel';
    public const REFUND_PATH = '/v1/refund';

    /** Set by AxiTrace on a 2xx to a request whose secret key did not verify: costs were dropped. */
    public const COST_KEY_HEADER = 'x-axitrace-cost-key';

    /**
     * Body cap of POST /v1/plugin/pixel (ingestion-api pluginPixelMaxBodyBytes). A larger body is
     * a 413, recorded as refused and never retried; a 200-line order stays far below it.
     */
    public const MAX_PIXEL_BODY_BYTES = 512 * 1024;

    public const CONNECT_TIMEOUT_SECONDS = 1;
    public const TOTAL_TIMEOUT_SECONDS = 2;

    public function __construct(private readonly HttpTransportInterface $transport)
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws SecretKeyRejectedException when a request sent with $secretKey got HTTP 401
     */
    public function sendPixel(string $apiBaseUrl, array $payload, string $secretKey): DeliveryResult
    {
        return $this->post(rtrim($apiBaseUrl, '/') . self::PIXEL_PATH, $payload, $secretKey);
    }

    /**
     * A refund or cancellation for profit (`/v1/refund`). That endpoint accepts secret-key
     * authentication only, so a refund is never sent without a key.
     *
     * @param array<string, mixed> $payload
     *
     * @throws SecretKeyRejectedException when AxiTrace rejected the key (never resent)
     */
    public function sendRefund(string $apiBaseUrl, array $payload, string $secretKey): DeliveryResult
    {
        if ($secretKey === '') {
            throw new \InvalidArgumentException('AxiTrace refunds require the secret key.');
        }

        return $this->post(rtrim($apiBaseUrl, '/') . self::REFUND_PATH, $payload, $secretKey);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws SecretKeyRejectedException when a request sent with $secretKey got HTTP 401
     */
    public function post(string $url, array $payload, string $secretKey): DeliveryResult
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($secretKey !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($secretKey . ':');
        }

        $body = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION);
        if ($body === false) {
            return new DeliveryResult(DeliveryOutcome::Rejected, null, 'payload could not be encoded as JSON: ' . json_last_error_msg());
        }

        try {
            $response = $this->transport->post($url, $headers, $body, self::CONNECT_TIMEOUT_SECONDS, self::TOTAL_TIMEOUT_SECONDS);
        } catch (HttpTransportException $e) {
            return new DeliveryResult(DeliveryOutcome::Retry, null, 'transport error: ' . mb_substr($e->getMessage(), 0, 200));
        }

        $status = $response->status;
        if ($status === 401 && $secretKey !== '') {
            throw new SecretKeyRejectedException('HTTP 401 for the configured secret key');
        }

        $outcome = self::map($status, $secretKey !== '');
        if ($outcome === DeliveryOutcome::Sent) {
            return new DeliveryResult($outcome, $status, '', $response->header(self::COST_KEY_HEADER));
        }

        return new DeliveryResult($outcome, $status, sprintf('HTTP %d%s', $status, self::errorDetail($response->body)));
    }

    /**
     * Status -> outcome. 2xx sent; 401 without a key = unknown public key; 400/413/415 = the
     * payload is refused (no retry); 408, 429, 5xx and anything unexpected (a proxy's 403/404
     * in front of a custom tracking domain) are retried with backoff, then marked dead.
     */
    public static function map(int $status, bool $withSecretKey): DeliveryOutcome
    {
        return match (true) {
            $status >= 200 && $status < 300 => DeliveryOutcome::Sent,
            $status === 401 && !$withSecretKey => DeliveryOutcome::PublicKeyRejected,
            in_array($status, [400, 413, 415], true) => DeliveryOutcome::Rejected,
            default => DeliveryOutcome::Retry,
        };
    }

    /**
     * The purchase without any per-line `unitCost`: what may travel on a request that is not
     * authenticated with the secret key.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function withoutUnitCosts(array $payload): array
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data) || !is_array($data['products'] ?? null)) {
            return $payload;
        }

        foreach ($data['products'] as $i => $product) {
            if (is_array($product)) {
                unset($data['products'][$i]['unitCost']);
            }
        }
        $payload['data'] = $data;

        return $payload;
    }

    /** The plain-text reason AxiTrace gave ("eventSalt required"), bounded; never a full body. */
    private static function errorDetail(string $body): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
        if ($text === '') {
            return '';
        }

        return ': ' . mb_substr($text, 0, 120);
    }
}
