<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Hook\Front;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Storefront\CartLine;
use Axitrace\PrestaShop\Storefront\CartView;
use Axitrace\PrestaShop\Storefront\ConfirmationView;
use Axitrace\PrestaShop\Storefront\ProductView;

/**
 * The page context the storefront script turns into commerce events, rendered as the JSON element
 * `axitrace-prestashop-context`: the product (product page), the cart (cart and checkout pages)
 * or the order (confirmation page). Product and cart data only, except the confirmation page's
 * browser purchase, which carries the buyer's own order (that page is shown to the buyer only).
 */
final class PageContextRenderer
{
    public const ELEMENT_ID = 'axitrace-prestashop-context';

    public const TYPE_PRODUCT = 'product';
    public const TYPE_CART = 'cart';
    public const TYPE_CHECKOUT = 'checkout';
    public const TYPE_CONFIRMATION = 'confirmation';

    /** php_self of the pages whose context is the cart (T1: identical on 8.1.7 and 9.2.0). */
    public const CART_PAGES = ['cart' => self::TYPE_CART, 'order' => self::TYPE_CHECKOUT];

    /** Bounded: a product with hundreds of combinations must not bloat every product page. */
    public const MAX_VARIANT_PRICES = 100;

    /** Cart page type for a php_self, or null when the page has no cart context. */
    public static function cartPageType(string $phpSelf): ?string
    {
        return self::CART_PAGES[$phpSelf] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function product(ProductView $product): array
    {
        $variants = [];
        foreach (array_slice($product->variantPrices, 0, self::MAX_VARIANT_PRICES, true) as $variantId => $price) {
            $variants[(string) $variantId] = self::money($price);
        }

        return [
            'type' => self::TYPE_PRODUCT,
            'currency' => $product->currency,
            'product' => [
                'productId' => (string) $product->productId,
                'variantId' => (string) $product->variantId,
                'name' => $product->name,
                'sku' => $product->sku,
                'price' => self::money($product->price),
                'category' => $product->category,
                'brand' => $product->brand,
                'variants' => (object) $variants,
            ],
        ];
    }

    /**
     * @param string $type {@see TYPE_CART} or {@see TYPE_CHECKOUT}
     *
     * @return array<string, mixed>
     */
    public function cart(string $type, CartView $cart): array
    {
        if ($type !== self::TYPE_CART && $type !== self::TYPE_CHECKOUT) {
            throw new \InvalidArgumentException(sprintf('Unknown cart context type "%s".', $type));
        }

        $items = [];
        $count = 0;
        foreach ($cart->lines as $line) {
            $items[] = self::line($line);
            $count += $line->quantity;
        }

        return [
            'type' => $type,
            'currency' => $cart->currency,
            'value' => self::money($cart->value),
            'itemCount' => $count,
            'items' => $items,
        ];
    }

    /**
     * The confirmation page context. `purchase` (the SDK transaction.charge parameters) and its
     * `eventSalt` are present only when the order is send-eligible at render time (A20); the
     * confirmation page is rendered for the buyer only (PrestaShop checks the cart's secure key).
     *
     * @param array<string, mixed>|null $purchase {@see \Axitrace\PrestaShop\Purchase\BrowserPurchase}
     *
     * @return array<string, mixed>
     */
    public function confirmation(ConfirmationView $order, ?array $purchase = null): array
    {
        $context = [
            'type' => self::TYPE_CONFIRMATION,
            'orderId' => (string) $order->orderId,
            'orderReference' => $order->orderReference,
            'currency' => $order->currency,
        ];

        $eventSalt = $purchase['eventSalt'] ?? null;
        if ($purchase !== null && is_string($eventSalt) && $eventSalt !== '') {
            $context['eventSalt'] = $eventSalt;
            $context['purchase'] = $purchase;
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function encode(array $context): string
    {
        return StorefrontJson::encode($context);
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(CartLine $line): array
    {
        return [
            'productId' => (string) $line->productId,
            'variantId' => (string) $line->variantId,
            'name' => $line->name,
            'sku' => $line->sku,
            'price' => self::money($line->price),
            'quantity' => $line->quantity,
            'category' => $line->category,
            'brand' => $line->brand,
        ];
    }

    private static function money(float $amount): float
    {
        return is_finite($amount) ? round($amount, 2) : 0.0;
    }
}
