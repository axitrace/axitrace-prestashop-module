<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Normalizer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reads the ad click ids of the current request (port of the Shopware plugin's
 * PersistedClickIdReader): from the URL when the request carries one, otherwise from the
 * first-party cookies the AxiTrace web SDK persists them in ("v2|<firstSeenMs>|<clickId>"),
 * otherwise (Microsoft, X, Pinterest, LinkedIn) from the cookie the platform's own tag writes.
 *
 * An unversioned cookie, a missing or non-numeric timestamp, an empty click id and a click older
 * than the web SDK's maximum age all yield nothing. A click id in the URL wins over the cookie,
 * except when it is the very click the cookie holds and that click is past its maximum age (a
 * bookmarked or shared landing URL, the SDK's isKnownStaleCookie()).
 *
 * fbclid is read from the URL only: Meta's click is carried by the _fbc cookie, which the
 * snapshot stores separately.
 */
final class ClickIdReader
{
    /** Click id key => [cookie name, maximum age in days], exactly as the web SDK writes them. */
    public const CLICK_IDS = [
        'gclid' => ['_gclid', 90],
        'gbraid' => ['_gbraid', 90],
        'wbraid' => ['_wbraid', 90],
        'ttclid' => ['_ttclid', 90],
        'rdt_cid' => ['_rdt_cid', 28],
        'oppref' => ['_oppref', 28],
        'msclkid' => ['_axi_msclkid', 90],
        'twclid' => ['_axi_twclid', 90],
        'epik' => ['_axi_epik', 60],
        'li_fat_id' => ['_axi_li_fat_id', 30],
        'sccid' => ['_axi_sccid', 28],
    ];

    /** Click ids read from the URL only. */
    public const URL_ONLY = ['fbclid'];

    /** URL parameters, in priority order, of a click id whose parameter differs from its key. */
    public const URL_PARAMS = [
        'sccid' => ['ScCid', 'sccid'],
    ];

    /** The platform's own cookie, read only as the last fallback. */
    public const VENDOR_COOKIES = [
        'msclkid' => '_uetmsclkid',
        'twclid' => '_twclid',
        'epik' => '_epik',
        'li_fat_id' => 'li_fat_id',
    ];

    private const VERSION_PREFIX = 'v2|';
    private const MS_PER_DAY = 86_400_000;
    private const CLICK_ID_PATTERN = '/^[\x21-\x7E]{1,500}$/';

    /**
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $query
     * @param int                   $nowMs current time in Unix milliseconds
     *
     * @return array<string, string> click id key => bare click id
     */
    public function read(array $cookies, array $query, int $nowMs): array
    {
        $clickIds = [];

        foreach (self::CLICK_IDS as $key => [$cookieName, $maxAgeDays]) {
            $cookie = $cookies[$cookieName] ?? null;

            $fromUrl = null;
            foreach (self::URL_PARAMS[$key] ?? [$key] as $param) {
                $fromUrl = self::clean($query[$param] ?? null);
                if ($fromUrl !== null) {
                    break;
                }
            }

            if ($fromUrl !== null) {
                if (!self::isKnownStaleCookie($cookie, $fromUrl, $maxAgeDays, $nowMs)) {
                    $clickIds[$key] = $fromUrl;
                }
                continue;
            }

            $parsed = $cookie !== null ? self::parse($cookie) : null;
            if ($parsed !== null && !self::isExpired($parsed['firstSeenMs'], $maxAgeDays, $nowMs)) {
                $clickIds[$key] = $parsed['clickId'];
                continue;
            }

            $vendorCookie = self::VENDOR_COOKIES[$key] ?? null;
            if ($vendorCookie !== null && isset($cookies[$vendorCookie])) {
                $fromVendor = self::fromVendorCookie($vendorCookie, $cookies[$vendorCookie]);
                if ($fromVendor !== null) {
                    $clickIds[$key] = $fromVendor;
                }
            }
        }

        foreach (self::URL_ONLY as $key) {
            $fromUrl = self::clean($query[$key] ?? null);
            if ($fromUrl !== null) {
                $clickIds[$key] = $fromUrl;
            }
        }

        return $clickIds;
    }

    /**
     * @return array{firstSeenMs: int, clickId: string}|null
     */
    private static function parse(string $raw): ?array
    {
        if (!str_starts_with($raw, self::VERSION_PREFIX)) {
            return null;
        }

        $parts = explode('|', $raw, 3);
        if (count($parts) !== 3 || preg_match('/^\d{1,15}$/', $parts[1]) !== 1) {
            return null;
        }

        $firstSeenMs = (int) $parts[1];
        $clickId = trim($parts[2]);
        if ($firstSeenMs <= 0 || preg_match(self::CLICK_ID_PATTERN, $clickId) !== 1) {
            return null;
        }

        return ['firstSeenMs' => $firstSeenMs, 'clickId' => $clickId];
    }

    private static function fromVendorCookie(string $cookieName, string $raw): ?string
    {
        $value = trim($raw);

        if ($cookieName === '_uetmsclkid' && str_starts_with($value, '_uet')) {
            $value = substr($value, 4);
        } elseif ($cookieName === '_twclid' && str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) && is_string($decoded['twclid'] ?? null) ? trim($decoded['twclid']) : '';
        }

        return preg_match(self::CLICK_ID_PATTERN, $value) === 1 ? $value : null;
    }

    private static function clean(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return preg_match(self::CLICK_ID_PATTERN, $value) === 1 ? $value : null;
    }

    private static function isKnownStaleCookie(?string $cookie, string $clickId, int $maxAgeDays, int $nowMs): bool
    {
        if ($cookie === null || $cookie === '') {
            return false;
        }

        if (!str_starts_with($cookie, self::VERSION_PREFIX)) {
            return trim($cookie) === $clickId;
        }

        $parsed = self::parse($cookie);
        if ($parsed === null || $parsed['clickId'] !== $clickId) {
            return false;
        }

        return self::isExpired($parsed['firstSeenMs'], $maxAgeDays, $nowMs);
    }

    private static function isExpired(int $firstSeenMs, int $maxAgeDays, int $nowMs): bool
    {
        return $nowMs - $firstSeenMs > $maxAgeDays * self::MS_PER_DAY;
    }
}
