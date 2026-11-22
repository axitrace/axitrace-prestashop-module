<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Domain;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The last store domain claim outcome of one shop, as stored in AXITRACE_DOMAIN_STATUS (per shop):
 * what AxiTrace answered for each host of the shop, and the error code when the claim itself
 * failed (the hosts are then the previous answer, if any).
 */
final class StoreDomainStatus
{
    public const VERIFIED = 'verified';
    public const PENDING = 'pending';

    /** Error codes set by the module itself (AxiTrace's own codes are stored as they come). */
    public const ERROR_NO_PUBLIC_KEY = 'no_public_key';
    public const ERROR_NO_STORE_HOST = 'no_store_host';
    public const ERROR_UNREACHABLE = 'unreachable';

    /**
     * @param list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}> $hosts
     */
    public function __construct(
        public readonly int $checkedAt,
        public readonly array $hosts,
        public readonly ?string $error,
    ) {
    }

    /** The stored value, or null when the shop never claimed or the value is unreadable. */
    public static function fromJson(?string $raw): ?self
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $value = json_decode($raw, true);
        if (!is_array($value)) {
            return null;
        }

        return new self(
            is_int($value['checked_at'] ?? null) ? $value['checked_at'] : 0,
            self::hostsOf(is_array($value['hosts'] ?? null) ? $value['hosts'] : []),
            is_string($value['error'] ?? null) ? $value['error'] : null,
        );
    }

    /**
     * The host entries of an AxiTrace answer (or of a stored status); malformed entries are dropped.
     *
     * @param array<mixed> $raw
     *
     * @return list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>
     */
    public static function hostsOf(array $raw): array
    {
        $hosts = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !is_string($entry['host'] ?? null) || !is_string($entry['status'] ?? null)) {
                continue;
            }
            $hosts[] = [
                'host' => $entry['host'],
                'status' => $entry['status'],
                'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : null,
                'browser_events_rejected' => ($entry['browser_events_rejected'] ?? false) === true,
            ];
        }

        return $hosts;
    }

    /**
     * @return array{host: string, status: string, reason: string|null, browser_events_rejected: bool}|null
     */
    public function host(string $host): ?array
    {
        foreach ($this->hosts as $entry) {
            if ($entry['host'] === $host) {
                return $entry;
            }
        }

        return null;
    }

    /** Written with `<`, `>` and `&` escaped: Configuration::updateValue() strips HTML tags. */
    public function toJson(): string
    {
        return json_encode(
            ['checked_at' => $this->checkedAt, 'hosts' => $this->hosts, 'error' => $this->error],
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_THROW_ON_ERROR,
        );
    }
}
