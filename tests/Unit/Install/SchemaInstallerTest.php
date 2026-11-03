<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Install;

use Axitrace\PrestaShop\Install\HookRegistrar;
use Axitrace\PrestaShop\Install\SchemaInstaller;
use Axitrace\PrestaShop\Tests\Unit\Support\RecordingDb;
use Axitrace\PrestaShop\Tests\Unit\Support\RecordingDbWithColumns;
use PHPUnit\Framework\TestCase;

final class SchemaInstallerTest extends TestCase
{
    public function testCreatesTheFourTablesIdempotentlyWithTheShopPrefix(): void
    {
        $db = new RecordingDb('shop7_', 'InnoDB');
        (new SchemaInstaller($db))->install();

        self::assertCount(4, $db->executed);
        foreach (['axitrace_order', 'axitrace_cart_snapshot', 'axitrace_refund', 'axitrace_failed_event'] as $i => $table) {
            self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `shop7_' . $table . '`', $db->executed[$i]);
            self::assertStringContainsString('ENGINE=InnoDB', $db->executed[$i]);
        }
    }

    public function testColumnsTheLaterTasksRelyOn(): void
    {
        $sql = implode("\n", (new SchemaInstaller(new RecordingDb()))->createStatements());

        foreach ([
            '`purchase_claimed_at` DATETIME NULL',
            '`purchase_sent_at` DATETIME NULL',
            '`event_id` CHAR(36) NULL',
            '`trigger` VARCHAR(16) NULL',
            '`hash` CHAR(64) NOT NULL',
            'UNIQUE KEY `uniq_axitrace_refund_refund_id` (`refund_id`)',
            '`amount` DECIMAL(20,6) NOT NULL',
            'KEY `idx_axitrace_failed_event_due` (`status`, `next_attempt_at`)',
            'KEY `idx_axitrace_cart_snapshot_updated` (`updated_at`)',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
    }

    public function testUnsafeEngineNameFallsBackToInnoDb(): void
    {
        $statements = (new SchemaInstaller(new RecordingDb('ps_', 'InnoDB; DROP TABLE x')))->createStatements();

        self::assertStringContainsString('ENGINE=InnoDB DEFAULT', $statements[0]);
    }

    public function testUninstallDropsEveryTable(): void
    {
        $db = new RecordingDb();
        (new SchemaInstaller($db))->uninstall();

        self::assertSame([
            'DROP TABLE IF EXISTS `ps_axitrace_order`',
            'DROP TABLE IF EXISTS `ps_axitrace_cart_snapshot`',
            'DROP TABLE IF EXISTS `ps_axitrace_refund`',
            'DROP TABLE IF EXISTS `ps_axitrace_failed_event`',
        ], $db->executed);
    }

    public function testHookRegistrarReportsFailedHooks(): void
    {
        $registered = [];
        $failed = (new HookRegistrar())->register(static function (string $hook) use (&$registered): bool {
            $registered[] = $hook;

            return $hook !== 'actionProductCancel';
        });

        self::assertSame(HookRegistrar::HOOKS, $registered);
        self::assertSame(['actionProductCancel'], $failed);
    }

    public function testModuleDeclaresAMethodForEveryRegisteredHook(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../axitrace.php');

        foreach (HookRegistrar::HOOKS as $hook) {
            self::assertStringContainsString(
                'public function ' . HookRegistrar::methodName($hook) . '(',
                $source,
                $hook . ' is registered but axitrace.php has no method for it',
            );
        }
    }

    public function testUpgradeAddsTheMissingPurchaseSnapshotColumnOnly(): void
    {
        $db = new RecordingDb('ps_');
        $added = (new SchemaInstaller($db))->upgrade();

        self::assertSame(['axitrace_order.purchase_snapshot'], $added);
        self::assertCount(5, $db->executed, '4 CREATE TABLE IF NOT EXISTS + 1 ALTER');
        self::assertSame('ALTER TABLE `ps_axitrace_order` ADD COLUMN `purchase_snapshot` LONGTEXT NULL AFTER `refund_state`', $db->executed[4]);
        foreach ($db->executed as $sql) {
            self::assertStringNotContainsString('purchase_sent_at', str_starts_with($sql, 'CREATE TABLE') ? '' : $sql, 'A39: never touches the sent flags');
            self::assertStringNotContainsStringIgnoringCase('DROP', $sql);
        }
    }

    public function testUpgradeOnAnUpToDateTableAddsNothing(): void
    {
        $db = new RecordingDbWithColumns('ps_');

        self::assertSame([], (new SchemaInstaller($db))->upgrade());
        self::assertCount(4, $db->executed);
    }

    public function testFreshInstallAlreadyHasThePurchaseSnapshotColumn(): void
    {
        self::assertStringContainsString('`purchase_snapshot` LONGTEXT NULL', (new SchemaInstaller(new RecordingDb()))->createStatements()[0]);
    }
}
