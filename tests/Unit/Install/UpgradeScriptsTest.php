<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Install;

use Axitrace\PrestaShop\Config\ModuleConfig;
use PHPUnit\Framework\TestCase;

/**
 * An upgrade script runs in the request that uploaded the new ZIP, where the previous version's
 * Composer autoloader (authoritative class map) is already registered: a module class added in the
 * new version cannot be loaded there, the upgrade fails and PrestaShop disables the module (seen on
 * a real 8.1.7 upgrade 1.0.0 -> 1.1.0). Scripts of releases after 1.0.0 use PrestaShop classes only.
 */
final class UpgradeScriptsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function scriptsAfter100(): iterable
    {
        foreach (glob(dirname(__DIR__, 3) . '/upgrade/upgrade-*.php') ?: [] as $file) {
            if (preg_match('/upgrade-(\d+\.\d+\.\d+)\.php$/', $file, $m) === 1 && version_compare($m[1], '1.0.0', '>')) {
                yield basename($file) => [$file];
            }
        }
    }

    /** @dataProvider scriptsAfter100 */
    public function testUsesNoModuleClass(string $file): void
    {
        $code = (string) file_get_contents($file);

        self::assertDoesNotMatchRegularExpression('/Axitrace\\\\PrestaShop\\\\/', $code, basename($file) . ' must not reference a module class');
    }

    public function testUpgrade110MakesTheNextRequestClaim(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 3) . '/upgrade/upgrade-1.1.0.php');

        self::assertStringContainsString("Configuration::updateGlobalValue('" . ModuleConfig::DOMAIN_CLAIM_DUE_AT . "', '0')", $code);
    }
}
