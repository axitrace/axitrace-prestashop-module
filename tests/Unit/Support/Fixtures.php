<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Purchase\OrderStateIds;
use Axitrace\PrestaShop\Purchase\PurchaseCustomer;
use Axitrace\PrestaShop\Purchase\PurchaseLine;
use Axitrace\PrestaShop\Purchase\PurchaseOrder;

/**
 * Reads the T1 captures (tests/fixtures/ps/<tag>/*.json): orders as the order reader would build
 * them, the order states table, and the status hook calls in capture order.
 */
final class Fixtures
{
    public const FULL_TIER = ['8.1.7', '9.2.0'];

    /** Synthetic buyer: the captures hold no customer data. */
    public static function customer(): PurchaseCustomer
    {
        return new PurchaseCustomer('buyer@example.com', '+48500100200', 'Ada', 'Lovelace', 'Warsaw', '00-001', 'PL', '');
    }

    /**
     * @return array<string, mixed>
     */
    public static function capture(string $tag, string $name): array
    {
        $path = __DIR__ . "/../../fixtures/ps/$tag/$name.json";
        $decoded = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Unreadable fixture ' . $path);
        }

        return $decoded;
    }

    /**
     * Order states of the tag: id => [paid, logable, name].
     *
     * @return array<int, array{paid: bool, logable: bool, name: string}>
     */
    public static function states(string $tag): array
    {
        $states = [];
        foreach (self::capture($tag, 'facts')['order_states'] as $state) {
            $states[(int) $state['id_order_state']] = [
                'paid' => (int) $state['paid'] === 1,
                'logable' => (int) $state['logable'] === 1,
                'name' => (string) $state['name'],
            ];
        }

        return $states;
    }

    public static function stateIds(string $tag): OrderStateIds
    {
        $config = self::capture($tag, 'facts')['configuration'];

        return new OrderStateIds((int) $config['PS_OS_ERROR'], (int) $config['PS_OS_CANCELED'], (int) $config['PS_OS_REFUND']);
    }

    public static function timezone(string $tag): string
    {
        return (string) self::capture($tag, 'facts')['configuration']['PS_TIMEZONE'];
    }

    /**
     * Every order of a capture, as the order reader builds it (DB values cast like the adapter).
     *
     * @return list<PurchaseOrder>
     */
    public static function orders(string $tag, string $name): array
    {
        $capture = self::capture($tag, $name);
        $states = self::states($tag);

        $orders = [];
        foreach ($capture['result']['facts'] as $fact) {
            $order = $fact['order'][0];
            $currency = $fact['currency'][0]['iso_code'] ?? 'EUR';
            $lines = [];
            // The captures carry no product names: the demo catalogue's names are used.
            foreach ($fact['order_detail'] as $detail) {
                $lines[] = new PurchaseLine(
                    (int) $detail['product_id'],
                    (int) $detail['product_attribute_id'],
                    (string) $detail['product_reference'],
                    (int) $detail['product_id'] === 1 ? 'Hummingbird printed t-shirt - Size : S- Color : White' : 'Mug The best is yet to come',
                    (int) $detail['product_quantity'],
                    (float) $detail['unit_price_tax_incl'],
                    (float) $detail['unit_price_tax_excl'],
                    (int) $detail['product_id'] === 1 ? 'Men' : 'Home Accessories',
                    'Studio Design',
                );
            }
            $stateId = (int) $order['current_state'];
            $orders[] = new PurchaseOrder(
                (int) $order['id_order'],
                (int) $order['id_shop'],
                (int) $order['id_shop_group'],
                (int) $order['id_cart'],
                (string) $order['reference'],
                (string) $order['module'],
                (string) $currency,
                (float) $order['total_paid_tax_incl'],
                (float) $order['total_paid_tax_excl'],
                (float) $order['total_shipping_tax_incl'],
                (float) $order['total_shipping_tax_excl'],
                (string) $order['date_add'],
                self::timezone($tag),
                $stateId,
                $states[$stateId]['paid'] ?? false,
                3,
                self::customer(),
                $lines,
            );
        }

        return $orders;
    }

    /**
     * The actionOrderStatusPostUpdate calls of a capture, in order: [id_order, new state id, raw paid].
     *
     * @return list<array{0: int, 1: int, 2: mixed}>
     */
    public static function statusCalls(string $tag, string $name): array
    {
        $calls = [];
        foreach (self::capture($tag, $name)['hooks'] as $hook) {
            if ($hook['hook'] !== 'actionOrderStatusPostUpdate') {
                continue;
            }
            $calls[] = [(int) $hook['params']['id_order'], (int) $hook['params']['newOrderStatus']['id'], $hook['params']['newOrderStatus']['paid']];
        }

        return $calls;
    }

    /**
     * A copy of the order with some constructor arguments replaced, by name.
     *
     * @param array<string, mixed> $changes
     */
    public static function with(PurchaseOrder $order, array $changes): PurchaseOrder
    {
        return new PurchaseOrder(...array_merge(get_object_vars($order), $changes));
    }
}
