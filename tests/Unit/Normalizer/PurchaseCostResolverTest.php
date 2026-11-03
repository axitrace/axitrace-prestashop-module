<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Normalizer;

use Axitrace\PrestaShop\Normalizer\PurchaseCostResolver;
use Axitrace\PrestaShop\Purchase\PurchaseLine;
use Axitrace\PrestaShop\Tests\Unit\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PurchaseCostResolverTest extends TestCase
{
    private static function line(float $supplier, float $original, float $current): PurchaseLine
    {
        return new PurchaseLine(1, 1, 'demo_1', 'T-shirt', 2, 19.12, 19.12, '', '', 10, $supplier, $original, $current);
    }

    /**
     * A37 order: purchase_supplier_price -> original_wholesale_price -> current wholesale_price.
     *
     * @return iterable<string, array{0: float, 1: float, 2: float, 3: float, 4: float|null}>
     */
    public static function resolutionOrder(): iterable
    {
        yield 'supplier price first' => [5.49, 7.5, 12.0, 1.0, 5.49];
        yield 'original wholesale when no supplier price' => [0.0, 7.5, 12.0, 1.0, 7.5];
        yield 'current wholesale when the order line has none' => [0.0, 0.0, 12.0, 1.0, 12.0];
        yield 'no cost anywhere' => [0.0, 0.0, 0.0, 1.0, null];
        yield 'negative values are not costs' => [-1.0, 0.0, 3.0, 1.0, 3.0];
        yield 'converted to the order currency' => [5.49, 7.5, 12.0, 1.11942, 6.1456];
        yield 'fallback converted too' => [0.0, 0.0, 10.0, 1.11942, 11.1942];
        yield 'conversion rate 0 gives no cost' => [5.49, 7.5, 12.0, 0.0, null];
        yield 'negative conversion rate gives no cost' => [5.49, 7.5, 12.0, -1.0, null];
        yield 'NaN cost skipped' => [NAN, 7.5, 12.0, 1.0, 7.5];
    }

    #[DataProvider('resolutionOrder')]
    public function testResolutionOrderAndConversion(float $supplier, float $original, float $current, float $rate, ?float $expected): void
    {
        self::assertSame($expected, (new PurchaseCostResolver())->unitCost(self::line($supplier, $original, $current), $rate));
    }

    public function testNonFiniteConversionRateGivesNoCost(): void
    {
        self::assertNull((new PurchaseCostResolver())->unitCost(self::line(5.49, 0.0, 0.0), INF));
    }

    /**
     * T1 captures (finding 5): order_detail cost columns are in the shop default currency, so a USD
     * order (conversion rate 1.119420) multiplies them; the EUR order uses them as they are.
     */
    public function testCapturedOrdersInBothCurrencies(): void
    {
        $resolver = new PurchaseCostResolver();
        foreach (Fixtures::FULL_TIER as $tag) {
            $eur = Fixtures::orders($tag, 'b1_wire_order')[0];
            self::assertSame(1.0, $eur->conversionRate, $tag);
            self::assertSame(5.49, $resolver->unitCost($eur->lines[0], $eur->conversionRate), $tag . ' t-shirt: supplier price');
            self::assertSame(10.0, $resolver->unitCost($eur->lines[1], $eur->conversionRate), $tag . ' mug: wholesale');

            $usd = Fixtures::orders($tag, 'h1_foreign_currency_order')[0];
            self::assertSame('USD', $usd->currency, $tag);
            self::assertSame(1.11942, $usd->conversionRate, $tag);
            self::assertSame(6.1456, $resolver->unitCost($usd->lines[0], $usd->conversionRate), $tag);
            self::assertSame(11.1942, $resolver->unitCost($usd->lines[1], $usd->conversionRate), $tag);
        }
    }
}
