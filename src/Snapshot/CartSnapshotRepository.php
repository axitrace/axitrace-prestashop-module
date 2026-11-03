<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Snapshot;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;

/**
 * Table `axitrace_cart_snapshot`: one attribution snapshot per cart, upserted from the buyer's
 * cart and checkout page views (A24), pruned after {@see RETENTION_DAYS} days.
 */
final class CartSnapshotRepository
{
    public const RETENTION_DAYS = 30;

    public function __construct(private readonly DbGatewayInterface $db)
    {
    }

    /**
     * @return array{snapshot: array<string, mixed>, hash: string}|null
     */
    public function find(int $idCart): ?array
    {
        if ($idCart <= 0) {
            return null;
        }

        $rows = $this->db->select(sprintf(
            'SELECT `snapshot`, `hash` FROM `%saxitrace_cart_snapshot` WHERE `id_cart` = %d LIMIT 1',
            $this->db->prefix(),
            $idCart,
        ));
        if ($rows === []) {
            return null;
        }

        $snapshot = SnapshotBuilder::decode((string) ($rows[0]['snapshot'] ?? ''));
        if ($snapshot === null) {
            return null;
        }

        return ['snapshot' => $snapshot, 'hash' => (string) ($rows[0]['hash'] ?? '')];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function save(int $idCart, int $idShop, array $snapshot, string $hash, \DateTimeImmutable $now): void
    {
        $this->db->execute(sprintf(
            "INSERT INTO `%saxitrace_cart_snapshot` (`id_cart`, `id_shop`, `snapshot`, `hash`, `updated_at`) VALUES (%d, %d, '%s', '%s', '%s')"
            . ' ON DUPLICATE KEY UPDATE id_shop = VALUES(`id_shop`), `snapshot` = VALUES(`snapshot`), `hash` = VALUES(`hash`), `updated_at` = VALUES(`updated_at`)',
            $this->db->prefix(),
            $idCart,
            $idShop,
            $this->db->escape(SnapshotBuilder::encode($snapshot)),
            $this->db->escape($hash),
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ));
    }

    /** Deletes snapshots not updated for {@see RETENTION_DAYS} days (called by the retry runner). */
    public function prune(\DateTimeImmutable $now): void
    {
        $cutoff = $now->setTimezone(new \DateTimeZone('UTC'))->modify(sprintf('-%d days', self::RETENTION_DAYS));

        $this->db->execute(sprintf(
            "DELETE FROM `%saxitrace_cart_snapshot` WHERE `updated_at` < '%s'",
            $this->db->prefix(),
            $cutoff->format('Y-m-d H:i:s'),
        ));
    }
}
