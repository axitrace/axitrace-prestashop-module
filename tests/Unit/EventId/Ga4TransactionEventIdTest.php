<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\EventId;

use Axitrace\PrestaShop\EventId\Ga4TransactionEventId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Three-way parity: the vectors were produced by the web SDK's own uuidV5 and are the ones
 * event-worker's Ga4TransactionEventIdParityTest pins (lines 33-37), so the module, the SDK and
 * event-worker derive the same purchase id.
 */
final class Ga4TransactionEventIdTest extends TestCase
{
    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function parityVectors(): \Generator
    {
        $lines = file(__DIR__ . '/../../fixtures/ga4_uuid_parity_vector.txt', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        foreach ($lines as $line) {
            $vector = json_decode($line, true, 2, \JSON_THROW_ON_ERROR);
            self::assertIsArray($vector);
            [$publicKey, $transactionId, $expected] = $vector;
            yield $publicKey . ' ' . $transactionId => [(string) $publicKey, (string) $transactionId, (string) $expected];
        }
    }

    #[DataProvider('parityVectors')]
    public function testMatchesTheSdkAndEventWorker(string $publicKey, string $transactionId, string $expected): void
    {
        self::assertSame($expected, Ga4TransactionEventId::fromTransaction($publicKey, $transactionId));
    }

    public function testAllFiveVectorsArePresent(): void
    {
        self::assertCount(5, iterator_to_array(self::parityVectors()));
    }

    public function testRfc4122Version5(): void
    {
        // RFC 4122 appendix / Python uuid.uuid5(uuid.NAMESPACE_DNS, 'python.org').
        self::assertSame('886313e1-3b8a-5372-9b90-0c9aee199e5d', Ga4TransactionEventId::uuidV5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'python.org'));
    }

    public function testScopedByWorkspace(): void
    {
        self::assertNotSame(
            Ga4TransactionEventId::fromTransaction('pk_a', '1041'),
            Ga4TransactionEventId::fromTransaction('pk_b', '1041'),
        );
    }
}
