<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Hook\Front\PageContextRenderer;
use Axitrace\PrestaShop\Storefront\CartLine;
use Axitrace\PrestaShop\Storefront\CartView;
use Axitrace\PrestaShop\Storefront\ConfirmationView;
use Axitrace\PrestaShop\Storefront\ProductView;

/**
 * Reads the product, cart and order shown on a storefront page into plain views. The only
 * storefront class touching PrestaShop objects; every value is cast (8.2 returns DB strings).
 */
final class PrestaShopStorefrontReader
{
    public function __construct(private readonly \Context $context)
    {
    }

    /**
     * @param int $variantId the combination shown on the page (0 = the default combination)
     */
    public function product(int $productId, int $variantId, bool $withTax): ?ProductView
    {
        $idLang = (int) ($this->context->language->id ?? 0);
        $product = new \Product($productId, false, $idLang, (int) ($this->context->shop->id ?? 0));
        if (!\Validate::isLoadedObject($product)) {
            return null;
        }

        if ($variantId <= 0) {
            $variantId = (int) \Product::getDefaultAttribute($productId);
        }

        $variantPrices = [];
        $count = 0;
        foreach (\Product::getProductAttributesIds($productId) as $row) {
            if (++$count > PageContextRenderer::MAX_VARIANT_PRICES) {
                break;
            }
            $id = (int) ($row['id_product_attribute'] ?? 0);
            if ($id > 0) {
                $variantPrices[$id] = (float) \Product::getPriceStatic($productId, $withTax, $id, 6);
            }
        }

        $category = new \Category((int) $product->id_category_default, $idLang);
        $name = is_string($product->name) ? $product->name : '';

        return new ProductView(
            $productId,
            $variantId,
            $name,
            (string) $product->reference,
            (float) \Product::getPriceStatic($productId, $withTax, $variantId > 0 ? $variantId : null, 6),
            $this->currencyIso((int) ($this->context->currency->id ?? 0)),
            \Validate::isLoadedObject($category) && is_string($category->name) ? $category->name : '',
            (int) $product->id_manufacturer > 0 ? (string) \Manufacturer::getNameById((int) $product->id_manufacturer) : '',
            $variantPrices,
        );
    }

    public function cart(bool $withTax, bool $withShipping): ?CartView
    {
        $cart = $this->context->cart;
        if (!$cart instanceof \Cart || !\Validate::isLoadedObject($cart)) {
            return null;
        }

        $lines = [];
        foreach ($cart->getProducts() as $row) {
            $lines[] = new CartLine(
                (int) ($row['id_product'] ?? 0),
                (int) ($row['id_product_attribute'] ?? 0),
                (string) ($row['name'] ?? ''),
                (string) ($row['reference'] ?? ''),
                (float) ($withTax ? ($row['price_wt'] ?? 0) : ($row['price'] ?? 0)),
                (int) ($row['cart_quantity'] ?? $row['quantity'] ?? 0),
                (string) ($row['category'] ?? ''),
                (string) ($row['manufacturer_name'] ?? ''),
            );
        }

        $type = $withShipping ? \Cart::BOTH : \Cart::BOTH_WITHOUT_SHIPPING;

        return new CartView(
            (float) $cart->getOrderTotal($withTax, $type),
            $this->currencyIso((int) $cart->id_currency),
            $lines,
        );
    }

    public function confirmation(mixed $order): ?ConfirmationView
    {
        if (!$order instanceof \Order || !\Validate::isLoadedObject($order)) {
            return null;
        }

        return new ConfirmationView((int) $order->id, (string) $order->reference, $this->currencyIso((int) $order->id_currency));
    }

    private function currencyIso(int $idCurrency): string
    {
        $currency = new \Currency($idCurrency);

        return \Validate::isLoadedObject($currency) ? strtoupper((string) $currency->iso_code) : '';
    }
}
