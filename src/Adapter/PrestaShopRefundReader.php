<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Purchase\PurchaseLine;
use Axitrace\PrestaShop\Refund\RefundSlip;
use Axitrace\PrestaShop\Refund\RefundSlipLine;

/**
 * Reads credit slips and order lines with plain queries through the module's DB gateway. Every
 * value is cast: 8.2 returns DB values as strings, the other versions as ints.
 */
final class PrestaShopRefundReader implements RefundReaderInterface
{
    public function __construct(
        private readonly DbGatewayInterface $db,
        private readonly ClockInterface $clock,
    ) {
    }

    public function slip(int $idOrder, ?int $idOrderSlip): ?RefundSlip
    {
        if ($idOrder <= 0) {
            return null;
        }

        $p = $this->db->prefix();
        $where = $idOrderSlip !== null && $idOrderSlip > 0
            ? sprintf('s.`id_order` = %d AND s.`id_order_slip` = %d', $idOrder, $idOrderSlip)
            : sprintf('s.`id_order` = %d ORDER BY s.`id_order_slip` DESC', $idOrder);

        $rows = $this->db->select(sprintf(
            'SELECT s.`id_order_slip`, s.`id_order`, s.`total_products_tax_incl`, s.`total_shipping_tax_incl`, s.`date_add`, o.`id_shop`, o.`id_shop_group`'
            . ' FROM `%1$sorder_slip` s JOIN `%1$sorders` o ON o.`id_order` = s.`id_order` WHERE %2$s LIMIT 1',
            $p,
            $where,
        ));
        $slip = $rows[0] ?? null;
        if ($slip === null) {
            return null;
        }

        $idSlip = (int) $slip['id_order_slip'];
        $lines = [];
        foreach ($this->db->select(sprintf(
            'SELECT d.`id_order_detail`, d.`product_quantity`, d.`amount_tax_incl`, od.`product_id`, od.`product_reference`'
            . ' FROM `%1$sorder_slip_detail` d LEFT JOIN `%1$sorder_detail` od ON od.`id_order_detail` = d.`id_order_detail`'
            . ' WHERE d.`id_order_slip` = %2$d ORDER BY d.`id_order_detail`',
            $p,
            $idSlip,
        )) as $row) {
            $lines[] = new RefundSlipLine(
                (int) $row['id_order_detail'],
                (int) ($row['product_id'] ?? 0),
                (string) ($row['product_reference'] ?? ''),
                (int) $row['product_quantity'],
                (float) $row['amount_tax_incl'],
            );
        }

        $timezone = (string) \Configuration::get('PS_TIMEZONE', null, (int) $slip['id_shop_group'], (int) $slip['id_shop']);

        return new RefundSlip(
            $idSlip,
            (int) $slip['id_order'],
            (float) $slip['total_products_tax_incl'],
            (float) $slip['total_shipping_tax_incl'],
            $this->utc((string) $slip['date_add'], $timezone),
            $lines,
        );
    }

    public function slipCount(int $idOrder): int
    {
        $rows = $this->db->select(sprintf(
            'SELECT COUNT(*) AS `slips` FROM `%sorder_slip` WHERE `id_order` = %d',
            $this->db->prefix(),
            $idOrder,
        ));

        return (int) ($rows[0]['slips'] ?? 0);
    }

    public function currentLine(int $idOrderDetail): ?PurchaseLine
    {
        $rows = $this->db->select(sprintf(
            'SELECT `id_order_detail`, `product_id`, `product_attribute_id`, `product_reference`, `product_name`, `product_quantity`, `unit_price_tax_incl`, `unit_price_tax_excl`'
            . ' FROM `%sorder_detail` WHERE `id_order_detail` = %d LIMIT 1',
            $this->db->prefix(),
            $idOrderDetail,
        ));
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        return new PurchaseLine(
            (int) $row['product_id'],
            (int) $row['product_attribute_id'],
            (string) $row['product_reference'],
            (string) $row['product_name'],
            (int) $row['product_quantity'],
            (float) $row['unit_price_tax_incl'],
            (float) $row['unit_price_tax_excl'],
            idOrderDetail: (int) $row['id_order_detail'],
        );
    }

    /** `date_add` is stored in the shop's PS_TIMEZONE; now when it cannot be read. */
    private function utc(string $local, string $timezone): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        try {
            $zone = new \DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        } catch (\Throwable) {
            $zone = $utc;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $local, $zone);
        if ($date === false || (int) $date->format('Y') < 2000) {
            return $this->clock->now()->setTimezone($utc);
        }

        return $date->setTimezone($utc);
    }
}
