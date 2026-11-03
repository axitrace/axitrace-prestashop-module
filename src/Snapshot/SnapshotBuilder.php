<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Snapshot;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\RequestContextInterface;
use Axitrace\PrestaShop\Consent\ConsentGate;
use Axitrace\PrestaShop\Normalizer\ClickIdReader;

/**
 * The attribution snapshot of the buyer's own request: AxiTrace visitor/session ids, ad platform
 * browser and click ids, the consent decision, IP address, user agent and the page URL. The
 * purchase is sent later (payment confirmation, IPN, back office) from a request that no longer
 * sees any of this, so it is captured on the cart and checkout pages and copied onto the order.
 *
 * Every cookie is validated against its known format (port of the Shopware plugin's
 * OrderPlacedSubscriber); a malformed or empty value is left out, never forwarded. Keys whose
 * value is unknown are absent.
 */
final class SnapshotBuilder
{
    private const FBP_PATTERN = '/^fb\.\d+\.\d+\.\d+$/';
    private const FBC_PATTERN = '/^fb\.\d+\.\d+\.[A-Za-z0-9_-]+$/';
    private const GA_PATTERN = '/^GA\d+\.\d+\.\d+\.\d+$/';
    private const GA_SESSION_PATTERN = '/^GS\d+\./';
    private const TTP_PATTERN = '/^[A-Za-z0-9_.-]{8,128}$/';
    private const RDT_UUID_PATTERN = '/^(\d{10,16}\.)?[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';
    private const OBREF_PATTERN = '/^[\x21-\x7E]{1,500}$/';
    private const AXITRACE_ID_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';

    private const MAX_UA_LENGTH = 512;
    private const MAX_GA_SESSION_LENGTH = 256;
    private const MAX_URL_LENGTH = 2048;

    /** Snapshot key => [cookie name, format]. */
    private const COOKIES = [
        'visitorId' => ['vt_vid', self::AXITRACE_ID_PATTERN],
        'sessionId' => ['vt_sid', self::AXITRACE_ID_PATTERN],
        'fbp' => ['_fbp', self::FBP_PATTERN],
        'fbc' => ['_fbc', self::FBC_PATTERN],
        'ga' => ['_ga', self::GA_PATTERN],
        'ttp' => ['_ttp', self::TTP_PATTERN],
        'rdtUuid' => ['_rdt_uuid', self::RDT_UUID_PATTERN],
        'obref' => ['__obref', self::OBREF_PATTERN],
    ];

    public function __construct(
        private readonly ClickIdReader $clickIdReader,
        private readonly ConsentGate $consentGate,
    ) {
    }

    /**
     * @param string $consentCookie the configured consent cookie name ('' = none)
     *
     * @return array<string, mixed>
     */
    public function build(RequestContextInterface $request, string $consentCookie, int $nowMs): array
    {
        $cookies = $request->cookies();
        $snapshot = [];

        foreach (self::COOKIES as $key => [$name, $pattern]) {
            $value = trim($cookies[$name] ?? '');
            if ($value !== '' && preg_match($pattern, $value) === 1) {
                $snapshot[$key] = $value;
            }
        }

        $gaSession = self::gaSession($cookies);
        if ($gaSession !== null) {
            $snapshot['gaSession'] = $gaSession;
        }

        $clickIds = $this->clickIdReader->read($cookies, $request->query(), $nowMs);
        if ($clickIds !== []) {
            ksort($clickIds);
            $snapshot['clickIds'] = $clickIds;
        }

        $consent = $this->consentGate->decisionFromCookie($consentCookie, $consentCookie !== '' ? ($cookies[$consentCookie] ?? null) : null);
        if ($consent !== null) {
            $snapshot['consent'] = $consent;
        }

        $ip = trim($request->clientIp());
        if ($ip !== '' && filter_var($ip, \FILTER_VALIDATE_IP) !== false) {
            $snapshot['clientIp'] = $ip;
        }

        $userAgent = trim($request->userAgent());
        if ($userAgent !== '') {
            $snapshot['userAgent'] = mb_substr($userAgent, 0, self::MAX_UA_LENGTH);
        }

        $url = $request->url();
        if ($url !== '' && strlen($url) <= self::MAX_URL_LENGTH && filter_var($url, \FILTER_VALIDATE_URL) !== false) {
            $snapshot['landingUrl'] = $url;
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * Stable content hash: the same data always hashes the same, whatever the key order.
     *
     * @param array<string, mixed> $snapshot
     */
    public static function hash(array $snapshot): string
    {
        return hash('sha256', self::encode(self::sorted($snapshot)));
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function encode(array $snapshot): string
    {
        return json_encode($snapshot, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        $snapshot = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $snapshot[$key] = $value;
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function sorted(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $data[$key] = self::sorted($value);
            }
        }

        return $data;
    }

    /**
     * The first GA4 per-property session cookie (_ga_<container>) with a valid value.
     *
     * @param array<string, string> $cookies
     */
    private static function gaSession(array $cookies): ?string
    {
        foreach ($cookies as $name => $value) {
            if (str_starts_with($name, '_ga_') && preg_match(self::GA_SESSION_PATTERN, $value) === 1) {
                return mb_substr($value, 0, self::MAX_GA_SESSION_LENGTH);
            }
        }

        return null;
    }
}
