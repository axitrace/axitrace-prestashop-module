<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\EventId;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The deterministic event id of a purchase: UUID v5 of "<publicKey>:ga4_transaction:<id_order>"
 * in the GA4_DATALAYER_ORDER_NAMESPACE of the AxiTrace web SDK. The browser purchase on the
 * confirmation page and the server purchase carry this same id, so AxiTrace keeps one of them.
 *
 * Three-way parity (web SDK, event-worker App\ValueObject\Ga4TransactionEventId, this class) is
 * pinned by tests/fixtures/ga4_uuid_parity_vector.txt. Changing the namespace or the name format
 * breaks the deduplication of every order.
 */
final class Ga4TransactionEventId
{
    /** web-sdk/velitrack-sdk.js GA4_DATALAYER_ORDER_NAMESPACE. Fixed forever. */
    public const NAMESPACE = '7c1a1f2e-9d3b-5a44-8f21-6b0c5d4e3a27';

    public static function fromTransaction(string $publicKey, string $transactionId): string
    {
        return self::uuidV5(self::NAMESPACE, $publicKey . ':ga4_transaction:' . $transactionId);
    }

    /** RFC 4122 name-based UUID, version 5 (SHA-1); the name is hashed as its UTF-8 bytes. */
    public static function uuidV5(string $namespace, string $name): string
    {
        $namespaceBytes = hex2bin(str_replace('-', '', $namespace));
        if ($namespaceBytes === false || strlen($namespaceBytes) !== 16) {
            throw new \InvalidArgumentException(sprintf('Invalid UUID namespace "%s".', $namespace));
        }

        $hash = sha1($namespaceBytes . $name);
        $timeHi = (hexdec(substr($hash, 12, 4)) & 0x0FFF) | 0x5000;
        $clockSeq = (hexdec(substr($hash, 16, 4)) & 0x3FFF) | 0x8000;

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            $timeHi,
            $clockSeq,
            substr($hash, 20, 12),
        );
    }
}
