<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Gdpr\CustomerRecords;
use Axitrace\PrestaShop\Gdpr\GdprSubject;

/**
 * Customer ids from the customer id itself, the e-mail address (`customer.email`, guests included)
 * or the phone number (`address.phone` / `phone_mobile`); then their orders (with the order's
 * cart) and the carts still owned by them. psgdpr 2.x anonymises the carts before it calls the
 * modules, so the carts of the customer's orders are reached through the orders.
 */
final class PrestaShopCustomerRecordsLocator implements CustomerRecordsLocatorInterface
{
    public function __construct(private readonly DbGatewayInterface $db)
    {
    }

    public function locate(GdprSubject $subject): CustomerRecords
    {
        $customerIds = $this->customerIds($subject);
        if ($customerIds === []) {
            return new CustomerRecords([], []);
        }

        $p = $this->db->prefix();
        $in = implode(',', $customerIds);

        $orderIds = [];
        $cartIds = [];
        foreach ($this->db->select(sprintf('SELECT `id_order`, `id_cart` FROM `%sorders` WHERE `id_customer` IN (%s)', $p, $in)) as $row) {
            $orderIds[] = (int) $row['id_order'];
            $cartIds[] = (int) $row['id_cart'];
        }
        foreach ($this->db->select(sprintf('SELECT `id_cart` FROM `%scart` WHERE `id_customer` IN (%s)', $p, $in)) as $row) {
            $cartIds[] = (int) $row['id_cart'];
        }

        return new CustomerRecords(self::ids($orderIds), self::ids($cartIds));
    }

    /**
     * @return list<int>
     */
    private function customerIds(GdprSubject $subject): array
    {
        $ids = $subject->customerId !== null ? [$subject->customerId] : [];
        $p = $this->db->prefix();

        if ($subject->email !== '') {
            foreach ($this->db->select(sprintf("SELECT `id_customer` FROM `%scustomer` WHERE `email` = '%s'", $p, $this->db->escape($subject->email))) as $row) {
                $ids[] = (int) $row['id_customer'];
            }
        }
        if ($subject->phone !== '') {
            $phone = $this->db->escape($subject->phone);
            foreach ($this->db->select(sprintf("SELECT DISTINCT `id_customer` FROM `%saddress` WHERE `id_customer` > 0 AND (`phone` = '%s' OR `phone_mobile` = '%s')", $p, $phone, $phone)) as $row) {
                $ids[] = (int) $row['id_customer'];
            }
        }

        return self::ids($ids);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
