<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Refund;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;

/**
 * Table `axitrace_refund`: one row per refund the module reported (or is reporting) for an order.
 *
 * {@see reserve()} is the guard against a double send: `refund_id` is UNIQUE, so of two hooks
 * firing for the same slip, line cancel or state, only one inserts and sends. A row stays while
 * the refund is delivered or queued for a retry (`sent_at` is set on delivery); it is released
 * when the refund can never be delivered (key rejected, refused, given up), so the amounts here
 * are what AxiTrace has received or will receive.
 */
final class RefundLedger
{
    public const KIND_SLIP = 'slip';
    public const KIND_LINE = 'line';
    public const KIND_STATE = 'state';

    public function __construct(private readonly DbGatewayInterface $db)
    {
    }

    /** True when this request recorded the refund; false when it was recorded before. */
    public function reserve(int $idOrder, string $refundId, string $kind, float $amount, string $currency): bool
    {
        if (!in_array($kind, [self::KIND_SLIP, self::KIND_LINE, self::KIND_STATE], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown refund kind "%s".', $kind));
        }

        return $this->db->update(sprintf(
            'INSERT IGNORE INTO `%saxitrace_refund` (`id_order`, `refund_id`, `kind`, `amount`, `currency`)'
            . " VALUES (%d, '%s', '%s', '%s', '%s')",
            $this->db->prefix(),
            $idOrder,
            $this->db->escape(mb_substr($refundId, 0, 128)),
            $kind,
            number_format($amount, 6, '.', ''),
            $this->db->escape(mb_substr(strtoupper($currency), 0, 3)),
        )) === 1;
    }

    public function markSent(string $refundId, \DateTimeImmutable $now): void
    {
        $this->db->update(sprintf(
            "UPDATE `%saxitrace_refund` SET `sent_at` = '%s' WHERE `refund_id` = '%s'",
            $this->db->prefix(),
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $this->db->escape($refundId),
        ));
    }

    /** Forgets a refund that will never reach AxiTrace. */
    public function release(string $refundId): void
    {
        $this->db->update(sprintf(
            "DELETE FROM `%saxitrace_refund` WHERE `refund_id` = '%s'",
            $this->db->prefix(),
            $this->db->escape($refundId),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrder(int $idOrder): array
    {
        return $this->db->select(sprintf(
            'SELECT * FROM `%saxitrace_refund` WHERE `id_order` = %d ORDER BY `id`',
            $this->db->prefix(),
            $idOrder,
        ));
    }

    /** The gross amount of every refund recorded for the order. */
    public function totalFor(int $idOrder): float
    {
        $total = 0.0;
        foreach ($this->forOrder($idOrder) as $row) {
            $total += (float) $row['amount'];
        }

        return $total;
    }

    public function hasKind(int $idOrder, string $kind): bool
    {
        foreach ($this->forOrder($idOrder) as $row) {
            if ($row['kind'] === $kind) {
                return true;
            }
        }

        return false;
    }
}
