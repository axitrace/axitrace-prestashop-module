<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Normalizer;

use Axitrace\PrestaShop\Normalizer\ClickIdReader;
use PHPUnit\Framework\TestCase;

final class ClickIdReaderTest extends TestCase
{
    private const NOW_MS = 1_793_000_000_000;
    private const DAY_MS = 86_400_000;

    public function testUrlWinsOverCookie(): void
    {
        $ids = (new ClickIdReader())->read(
            ['_gclid' => 'v2|' . (self::NOW_MS - self::DAY_MS) . '|OLD'],
            ['gclid' => 'NEW', 'fbclid' => 'IwAR1', 'ScCid' => 'snap-1'],
            self::NOW_MS,
        );

        self::assertSame(['gclid' => 'NEW', 'sccid' => 'snap-1', 'fbclid' => 'IwAR1'], $ids);
    }

    public function testSdkCookiesAreUnwrappedAndExpiredOnesDropped(): void
    {
        $ids = (new ClickIdReader())->read([
            '_gclid' => 'v2|' . (self::NOW_MS - 10 * self::DAY_MS) . '|G-1',
            '_ttclid' => 'v2|' . (self::NOW_MS - 91 * self::DAY_MS) . '|T-1',
            '_axi_msclkid' => 'v2|' . (self::NOW_MS - self::DAY_MS) . '|M-1',
            '_gbraid' => 'legacy-unversioned',
            '_wbraid' => 'v2|abc|W-1',
        ], [], self::NOW_MS);

        self::assertSame(['gclid' => 'G-1', 'msclkid' => 'M-1'], $ids);
    }

    public function testVendorCookiesAreTheLastFallback(): void
    {
        $ids = (new ClickIdReader())->read([
            '_uetmsclkid' => '_uetabc123',
            '_twclid' => '{"twclid":"tw-9","ts":1}',
            'li_fat_id' => 'li-1',
        ], [], self::NOW_MS);

        self::assertSame(['msclkid' => 'abc123', 'twclid' => 'tw-9', 'li_fat_id' => 'li-1'], $ids);
    }

    public function testAStaleBookmarkedClickIsNotANewClick(): void
    {
        $ids = (new ClickIdReader())->read(
            ['_gclid' => 'v2|' . (self::NOW_MS - 100 * self::DAY_MS) . '|SAME'],
            ['gclid' => 'SAME'],
            self::NOW_MS,
        );

        self::assertSame([], $ids);
    }

    public function testMalformedValuesAreRejected(): void
    {
        $ids = (new ClickIdReader())->read([], ['gclid' => 'has space', 'fbclid' => str_repeat('a', 501), 'ttclid' => ['array']], self::NOW_MS);

        self::assertSame([], $ids);
    }
}
