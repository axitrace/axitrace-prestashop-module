<?php
/**
 * AxiTrace for PrestaShop: upgrade to 0.1.0.
 *
 * @author    AxiTrace
 * @copyright AxiTrace
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\PrestaShopDbGateway;
use Axitrace\PrestaShop\Install\SchemaInstaller;

/**
 * 0.1.0 keeps what each purchase carried (`axitrace_order.purchase_snapshot`) for refunds. Adds the
 * column to an existing install; nothing else is changed.
 *
 * @param Axitrace $module
 */
function upgrade_module_0_1_0($module): bool
{
    try {
        (new SchemaInstaller(new PrestaShopDbGateway()))->upgrade();
    } catch (\Throwable $e) {
        PrestaShopLogger::addLog(sprintf('AxiTrace: upgrade to 0.1.0 failed (%s: %s)', $e::class, $e->getMessage()), 3, null, 'Axitrace', null, true);

        return false;
    }

    return true;
}
