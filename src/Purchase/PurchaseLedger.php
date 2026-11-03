<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Purchase;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;

/**
 * The purchase bookkeeping of `axitrace_order`: NEW (nothing set) -> CLAIMED
 * (`purchase_claimed_at`) -> SENT (`purchase_sent_at`); NEW -> VOID (`trigger` = 'void').
 *
 * {@see claim()} is the one guarantee against a second send: an atomic UPDATE that only one
 * request can win, whatever the number of status hooks firing for the order (repeated states
 * through OrderHistory, a COD order moved to a paid state, two processes at once).
 */
final class PurchaseLedger
{
    public const TRIGGER_VOID = 'void';

    public function __construct(private readonly DbGatewayInterface $db)
    {
    }

    /** True when this request won the claim; false when the order was claimed before (or has no row). */
    public function claim(int $idOrder, PurchaseDecision $trigger, \DateTimeImmutable $now): bool
    {
        if (!$trigger->isSend()) {
            throw new \InvalidArgumentException(sprintf('Only a send decision can claim an order, got "%s".', $trigger->value));
        }

        return $this->db->update(sprintf(
            "UPDATE `%saxitrace_order` SET `purchase_claimed_at` = '%s', `trigger` = '%s'"
            . ' WHERE `id_order` = %d AND `purchase_claimed_at` IS NULL',
            $this->db->prefix(),
            self::utc($now),
            $trigger->value,
            $idOrder,
        )) === 1;
    }

    public function markSent(int $idOrder, \DateTimeImmutable $now): void
    {
        $this->db->update(sprintf(
            "UPDATE `%saxitrace_order` SET `purchase_sent_at` = '%s' WHERE `id_order` = %d AND `purchase_sent_at` IS NULL",
            $this->db->prefix(),
            self::utc($now),
            $idOrder,
        ));
    }

    /**
     * Gives the claim back when the purchase never left the shop (the payload could not be
     * built), so the next status change tries again. Never used after a delivery attempt.
     */
    public function releaseClaim(int $idOrder): void
    {
        $this->db->update(sprintf(
            'UPDATE `%saxitrace_order` SET `purchase_claimed_at` = NULL, `trigger` = NULL'
            . ' WHERE `id_order` = %d AND `purchase_sent_at` IS NULL',
            $this->db->prefix(),
            $idOrder,
        ));
    }

    /** Records that the order was never a sale; does nothing once the order was claimed. */
    public function markVoid(int $idOrder): void
    {
        $this->db->update(sprintf(
            "UPDATE `%saxitrace_order` SET `trigger` = '%s' WHERE `id_order` = %d AND `purchase_claimed_at` IS NULL",
            $this->db->prefix(),
            self::TRIGGER_VOID,
            $idOrder,
        ));
    }

    /**
     * @param array<string, mixed> $row an `axitrace_order` row
     */
    public static function isClaimed(array $row): bool
    {
        return ($row['purchase_claimed_at'] ?? null) !== null && $row['purchase_claimed_at'] !== '';
    }

    /**
     * @param array<string, mixed> $row an `axitrace_order` row
     */
    public static function isVoid(array $row): bool
    {
        return !self::isClaimed($row) && ($row['trigger'] ?? null) === self::TRIGGER_VOID;
    }

    private static function utc(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
