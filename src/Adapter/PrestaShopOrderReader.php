<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Purchase\OrderStateIds;
use Axitrace\PrestaShop\Purchase\PurchaseCustomer;
use Axitrace\PrestaShop\Purchase\PurchaseLine;
use Axitrace\PrestaShop\Purchase\PurchaseOrder;

/**
 * The only class reading Order, Customer, Address and OrderDetail for the purchase pipeline. Every
 * value is cast: 8.2 returns DB values as strings, the other versions as ints.
 */
final class PrestaShopOrderReader implements OrderReaderInterface
{
    public function read(int $idOrder): ?PurchaseOrder
    {
        if ($idOrder <= 0) {
            return null;
        }

        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order)) {
            return null;
        }

        $idShop = (int) $order->id_shop;
        $idShopGroup = (int) $order->id_shop_group;
        $idLang = (int) $order->id_lang;

        $customer = new \Customer((int) $order->id_customer);
        $customerLoaded = \Validate::isLoadedObject($customer);
        $address = new \Address((int) $order->id_address_invoice);
        $addressLoaded = \Validate::isLoadedObject($address);
        $currency = new \Currency((int) $order->id_currency);

        $stateId = (int) $order->current_state;
        $state = $stateId > 0 ? new \OrderState($stateId) : null;

        $countryIso = '';
        $stateIso = '';
        if ($addressLoaded) {
            $countryIso = (string) \Country::getIsoById((int) $address->id_country);
            if ((int) $address->id_state > 0) {
                $region = new \State((int) $address->id_state);
                $stateIso = \Validate::isLoadedObject($region) ? (string) $region->iso_code : '';
            }
        }

        $lines = [];
        foreach ($order->getOrderDetailList() as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            [$category, $brand] = $this->categoryAndBrand($productId, $idLang, $idShop);
            $lines[] = new PurchaseLine(
                $productId,
                (int) ($row['product_attribute_id'] ?? 0),
                (string) ($row['product_reference'] ?? ''),
                (string) ($row['product_name'] ?? ''),
                (int) ($row['product_quantity'] ?? 0),
                (float) ($row['unit_price_tax_incl'] ?? 0),
                (float) ($row['unit_price_tax_excl'] ?? 0),
                $category,
                $brand,
            );
        }

        return new PurchaseOrder(
            (int) $order->id,
            $idShop,
            $idShopGroup,
            (int) $order->id_cart,
            (string) $order->reference,
            (string) $order->module,
            \Validate::isLoadedObject($currency) ? strtoupper((string) $currency->iso_code) : '',
            (float) $order->total_paid_tax_incl,
            (float) $order->total_paid_tax_excl,
            (float) $order->total_shipping_tax_incl,
            (float) $order->total_shipping_tax_excl,
            (string) $order->date_add,
            (string) \Configuration::get('PS_TIMEZONE', null, $idShopGroup, $idShop),
            $stateId,
            $state !== null && \Validate::isLoadedObject($state) && (int) $state->paid === 1,
            $customerLoaded ? (int) $customer->id_default_group : null,
            new PurchaseCustomer(
                $customerLoaded ? (string) $customer->email : '',
                $addressLoaded ? (string) ($address->phone_mobile !== '' && $address->phone_mobile !== null ? $address->phone_mobile : $address->phone) : '',
                $addressLoaded ? (string) $address->firstname : ($customerLoaded ? (string) $customer->firstname : ''),
                $addressLoaded ? (string) $address->lastname : ($customerLoaded ? (string) $customer->lastname : ''),
                $addressLoaded ? (string) $address->city : '',
                $addressLoaded ? (string) $address->postcode : '',
                $countryIso,
                $stateIso,
            ),
            $lines,
        );
    }

    public function stateIds(int $idShopGroup, int $idShop): OrderStateIds
    {
        return new OrderStateIds(
            (int) \Configuration::get('PS_OS_ERROR', null, $idShopGroup, $idShop),
            (int) \Configuration::get('PS_OS_CANCELED', null, $idShopGroup, $idShop),
            (int) \Configuration::get('PS_OS_REFUND', null, $idShopGroup, $idShop),
        );
    }

    /**
     * Default category name and manufacturer name of the product, '' when unknown.
     *
     * @return array{0: string, 1: string}
     */
    private function categoryAndBrand(int $productId, int $idLang, int $idShop): array
    {
        if ($productId <= 0) {
            return ['', ''];
        }

        $product = new \Product($productId, false, $idLang, $idShop);
        if (!\Validate::isLoadedObject($product)) {
            return ['', ''];
        }

        $category = new \Category((int) $product->id_category_default, $idLang);
        $categoryName = \Validate::isLoadedObject($category) && is_string($category->name) ? $category->name : '';
        $brand = (int) $product->id_manufacturer > 0 ? (string) \Manufacturer::getNameById((int) $product->id_manufacturer) : '';

        return [$categoryName, $brand];
    }
}
