<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;

/**
 * Creates and drops the module tables (A39). Every statement is idempotent
 * (CREATE TABLE IF NOT EXISTS / DROP TABLE IF EXISTS) and uses the shop's table prefix.
 */
final class SchemaInstaller
{
    public const TABLES = [
        'axitrace_order',
        'axitrace_cart_snapshot',
        'axitrace_refund',
        'axitrace_failed_event',
    ];

    public function __construct(private readonly DbGatewayInterface $db)
    {
    }

    public function install(): void
    {
        foreach ($this->createStatements() as $sql) {
            $this->db->execute($sql);
        }
    }

    public function uninstall(): void
    {
        foreach ($this->dropStatements() as $sql) {
            $this->db->execute($sql);
        }
    }

    /**
     * @return list<string>
     */
    public function createStatements(): array
    {
        $p = $this->db->prefix();
        $tail = sprintf(' ENGINE=%s DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $this->engine());

        return [
            'CREATE TABLE IF NOT EXISTS `' . $p . 'axitrace_order` ('
                . '`id_order` INT UNSIGNED NOT NULL,'
                . '`id_shop` INT UNSIGNED NOT NULL,'
                . '`id_shop_group` INT UNSIGNED NOT NULL,'
                . '`reference` VARCHAR(64) NULL,'
                . '`trigger` VARCHAR(16) NULL,'
                . '`event_id` CHAR(36) NULL,'
                . '`snapshot` LONGTEXT NULL,'
                . '`purchase_claimed_at` DATETIME NULL,'
                . '`purchase_sent_at` DATETIME NULL,'
                . '`refund_state` VARCHAR(32) NULL,'
                . '`purchase_snapshot` LONGTEXT NULL,'
                . '`created_at` DATETIME NOT NULL,'
                . 'PRIMARY KEY (`id_order`),'
                . 'KEY `idx_axitrace_order_shop` (`id_shop`)'
                . ')' . $tail,
            'CREATE TABLE IF NOT EXISTS `' . $p . 'axitrace_cart_snapshot` ('
                . '`id_cart` INT UNSIGNED NOT NULL,'
                . '`id_shop` INT UNSIGNED NOT NULL,'
                . '`snapshot` LONGTEXT NOT NULL,'
                . '`hash` CHAR(64) NOT NULL,'
                . '`updated_at` DATETIME NOT NULL,'
                . 'PRIMARY KEY (`id_cart`),'
                . 'KEY `idx_axitrace_cart_snapshot_updated` (`updated_at`)'
                . ')' . $tail,
            'CREATE TABLE IF NOT EXISTS `' . $p . 'axitrace_refund` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`id_order` INT UNSIGNED NOT NULL,'
                . '`refund_id` VARCHAR(128) NOT NULL,'
                . '`kind` VARCHAR(8) NOT NULL,'
                . '`amount` DECIMAL(20,6) NOT NULL,'
                . '`currency` CHAR(3) NOT NULL,'
                . '`sent_at` DATETIME NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uniq_axitrace_refund_refund_id` (`refund_id`),'
                . 'KEY `idx_axitrace_refund_order` (`id_order`)'
                . ')' . $tail,
            'CREATE TABLE IF NOT EXISTS `' . $p . 'axitrace_failed_event` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`event_id` VARCHAR(64) NOT NULL,'
                . '`endpoint` VARCHAR(16) NOT NULL,'
                . '`id_shop` INT UNSIGNED NOT NULL,'
                . '`payload` MEDIUMTEXT NOT NULL,'
                . '`attempts` INT UNSIGNED NOT NULL DEFAULT 0,'
                . '`next_attempt_at` DATETIME NOT NULL,'
                . '`last_error` VARCHAR(512) NULL,'
                . "`status` VARCHAR(8) NOT NULL DEFAULT 'queued',"
                . '`created_at` DATETIME NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'KEY `idx_axitrace_failed_event_due` (`status`, `next_attempt_at`)'
                . ')' . $tail,
        ];
    }

    /**
     * Columns added after a table was first created; {@see upgrade()} adds the missing ones to an
     * existing install (CREATE TABLE IF NOT EXISTS leaves an existing table as it is).
     */
    public const ADDED_COLUMNS = [
        'axitrace_order' => [
            // 0.1.0: what the purchase carried, for refunds (line cancel, rest of a cancellation).
            'purchase_snapshot' => 'LONGTEXT NULL AFTER `refund_state`',
        ],
    ];

    /**
     * Upgrade step: creates missing tables, then adds missing columns. Never drops, renames or
     * rewrites anything (A39: the claim/sent flags of existing orders are never touched).
     *
     * @return list<string> the columns added, as table.column
     */
    public function upgrade(): array
    {
        $this->install();

        $added = [];
        foreach (self::ADDED_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $exists = $this->db->select(sprintf(
                    "SHOW COLUMNS FROM `%s%s` LIKE '%s'",
                    $this->db->prefix(),
                    $table,
                    $this->db->escapeLike($column),
                ));
                if ($exists !== []) {
                    continue;
                }
                $this->db->execute(sprintf('ALTER TABLE `%s%s` ADD COLUMN `%s` %s', $this->db->prefix(), $table, $column, $definition));
                $added[] = $table . '.' . $column;
            }
        }

        return $added;
    }

    /**
     * @return list<string>
     */
    public function dropStatements(): array
    {
        $p = $this->db->prefix();

        return array_map(
            static fn (string $table): string => 'DROP TABLE IF EXISTS `' . $p . $table . '`',
            self::TABLES,
        );
    }

    private function engine(): string
    {
        $engine = $this->db->engine();

        return preg_match('/^[A-Za-z]+$/', $engine) === 1 ? $engine : 'InnoDB';
    }
}
