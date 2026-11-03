<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Purchase;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;
use Axitrace\PrestaShop\Refund\SentPurchase;
use Axitrace\PrestaShop\Snapshot\SnapshotBuilder;

/**
 * Table `axitrace_order`: the module's record of one PrestaShop order (event id, attribution
 * snapshot, purchase claim/sent flags). A new row is in state NEW: not claimed, not sent.
 */
final class OrderRowRepository
{
    public function __construct(private readonly DbGatewayInterface $db)
    {
    }

    /**
     * Inserts the row once; a second call for the same id_order changes nothing (INSERT IGNORE),
     * so a re-run hook can never reset a claimed or sent order.
     *
     * @param array<string, mixed>|null $snapshot
     */
    public function insertNew(
        int $idOrder,
        int $idShop,
        int $idShopGroup,
        string $reference,
        string $eventId,
        ?array $snapshot,
        \DateTimeImmutable $now,
    ): void {
        $this->db->execute(sprintf(
            'INSERT IGNORE INTO `%saxitrace_order` (`id_order`, `id_shop`, `id_shop_group`, `reference`, `event_id`, `snapshot`, `created_at`)'
            . " VALUES (%d, %d, %d, '%s', '%s', %s, '%s')",
            $this->db->prefix(),
            $idOrder,
            $idShop,
            $idShopGroup,
            $this->db->escape(mb_substr($reference, 0, 64)),
            $this->db->escape($eventId),
            $snapshot === null ? 'NULL' : "'" . $this->db->escape(SnapshotBuilder::encode($snapshot)) . "'",
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $idOrder): ?array
    {
        $rows = $this->db->select(sprintf(
            'SELECT * FROM `%saxitrace_order` WHERE `id_order` = %d LIMIT 1',
            $this->db->prefix(),
            $idOrder,
        ));

        return $rows[0] ?? null;
    }

    /** Keeps what the purchase carried, for the refunds computed later ({@see SentPurchase}). */
    public function recordSentPurchase(int $idOrder, SentPurchase $purchase): void
    {
        $this->db->update(sprintf(
            "UPDATE `%saxitrace_order` SET `purchase_snapshot` = '%s' WHERE `id_order` = %d",
            $this->db->prefix(),
            $this->db->escape($purchase->encode()),
            $idOrder,
        ));
    }

    /** `partially_refunded`, `refunded` or `cancelled`: what the reported refunds amount to. */
    public function setRefundState(int $idOrder, string $state): void
    {
        $this->db->update(sprintf(
            "UPDATE `%saxitrace_order` SET `refund_state` = '%s' WHERE `id_order` = %d",
            $this->db->prefix(),
            $this->db->escape($state),
            $idOrder,
        ));
    }
}
