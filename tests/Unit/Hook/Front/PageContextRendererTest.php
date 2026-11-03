<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Hook\Front;

use Axitrace\PrestaShop\Hook\Front\PageContextRenderer;
use Axitrace\PrestaShop\Storefront\CartLine;
use Axitrace\PrestaShop\Storefront\CartView;
use Axitrace\PrestaShop\Storefront\ConfirmationView;
use Axitrace\PrestaShop\Storefront\ProductView;
use PHPUnit\Framework\TestCase;

final class PageContextRendererTest extends TestCase
{
    public function testProductContext(): void
    {
        $renderer = new PageContextRenderer();
        $view = new ProductView(1, 1, 'Hummingbird printed t-shirt', 'demo_1', 23.9, 'EUR', 'Men', 'Studio Design', [1 => 23.9, 9 => 29.899999]);

        $context = json_decode($renderer->encode($renderer->product($view)), true);

        self::assertSame([
            'type' => 'product',
            'currency' => 'EUR',
            'product' => [
                'productId' => '1',
                'variantId' => '1',
                'name' => 'Hummingbird printed t-shirt',
                'sku' => 'demo_1',
                'price' => 23.9,
                'category' => 'Men',
                'brand' => 'Studio Design',
                'variants' => ['1' => 23.9, '9' => 29.9],
            ],
        ], $context);
    }

    public function testProductWithoutCombinationsRendersAnEmptyVariantObject(): void
    {
        $renderer = new PageContextRenderer();
        $json = $renderer->encode($renderer->product(new ProductView(6, 0, 'Mug', '', 11.9, 'EUR', '', '')));

        self::assertStringContainsString('"variants":{}', $json);
    }

    public function testVariantPricesAreBounded(): void
    {
        $prices = [];
        for ($i = 1; $i <= 250; ++$i) {
            $prices[$i] = 1.0;
        }
        $renderer = new PageContextRenderer();
        $context = $renderer->product(new ProductView(1, 1, 'x', '', 1.0, 'EUR', '', '', $prices));

        self::assertIsArray($context['product']);
        self::assertCount(PageContextRenderer::MAX_VARIANT_PRICES, (array) $context['product']['variants']);
    }

    public function testCartAndCheckoutContext(): void
    {
        $renderer = new PageContextRenderer();
        $cart = new CartView(57.14, 'USD', [
            new CartLine(1, 1, 'T-shirt', 'demo_1', 21.40331, 2, 'men', ''),
            new CartLine(6, 0, 'Mug', '', 13.32, 1, 'home-accessories', 'Studio'),
        ]);

        $context = $renderer->cart('checkout', $cart);

        self::assertSame('checkout', $context['type']);
        self::assertSame('USD', $context['currency']);
        self::assertSame(57.14, $context['value']);
        self::assertSame(3, $context['itemCount']);
        self::assertSame([
            'productId' => '1', 'variantId' => '1', 'name' => 'T-shirt', 'sku' => 'demo_1', 'price' => 21.4,
            'quantity' => 2, 'category' => 'men', 'brand' => '',
        ], $context['items'][0]);
        self::assertSame('cart', $renderer->cart('cart', $cart)['type']);
    }

    public function testCartPageTypesFromT1(): void
    {
        self::assertSame('cart', PageContextRenderer::cartPageType('cart'));
        self::assertSame('checkout', PageContextRenderer::cartPageType('order'));
        self::assertNull(PageContextRenderer::cartPageType('order-confirmation'));
        self::assertNull(PageContextRenderer::cartPageType('product'));
    }

    public function testUnknownCartTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PageContextRenderer())->cart('product', new CartView(0.0, 'EUR', []));
    }

    public function testConfirmationContextHasNoCustomerData(): void
    {
        $renderer = new PageContextRenderer();
        $context = $renderer->confirmation(new ConfirmationView(12, 'XKBKNABJK', 'EUR'));

        self::assertSame(['type' => 'confirmation', 'orderId' => '12', 'orderReference' => 'XKBKNABJK', 'currency' => 'EUR'], $context);
    }

    public function testHostileProductNameCannotCloseTheElement(): void
    {
        $renderer = new PageContextRenderer();
        $json = $renderer->encode($renderer->product(new ProductView(1, 0, '</script><script>alert(1)</script>', "a'b\"c&d", 1.0, 'EUR', '', '')));

        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('&', $json);
        self::assertStringNotContainsString("'", $json);
        self::assertSame('</script><script>alert(1)</script>', json_decode($json, true)['product']['name']);
    }

    public function testPricesAreShortEvenWithAHighSerializePrecision(): void
    {
        $previous = (string) ini_get('serialize_precision');
        ini_set('serialize_precision', '17');
        try {
            $renderer = new PageContextRenderer();
            $json = $renderer->encode($renderer->product(new ProductView(1, 1, 'x', '', 19.12, 'EUR', '', '', [1 => 19.12])));
            $after = ini_get('serialize_precision');
        } finally {
            ini_set('serialize_precision', $previous);
        }

        self::assertStringContainsString('"price":19.12,', $json);
        self::assertStringContainsString('{"1":19.12}', $json);
        self::assertSame('17', $after, 'the setting is restored after encoding');
    }
}
