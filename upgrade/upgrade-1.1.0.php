<?php
/**
 * AxiTrace for PrestaShop: upgrade to 1.1.0.
 *
 * @author    AxiTrace
 * @copyright AxiTrace
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.1.0 verifies the store domains automatically. The first back-office, storefront or cron request
 * after the upgrade claims them (no claim inside the upgrade request itself, which must stay short):
 * AXITRACE_DOMAIN_CLAIM_DUE_AT = 0, as StoreDomainClaimTrigger::claimSoon() writes it.
 *
 * Only PrestaShop classes here: the upgrade runs in the request that uploaded the new ZIP, where the
 * 1.0.0 Composer autoloader (authoritative class map) is already registered and knows none of the
 * classes added in 1.1.0. Using one failed the upgrade, and PrestaShop then disabled the module.
 *
 * @param Axitrace $module
 */
function upgrade_module_1_1_0($module): bool
{
    try {
        if (!Configuration::updateGlobalValue('AXITRACE_DOMAIN_CLAIM_DUE_AT', '0')) {
            throw new RuntimeException('Configuration value AXITRACE_DOMAIN_CLAIM_DUE_AT could not be saved.');
        }
    } catch (\Throwable $e) {
        PrestaShopLogger::addLog(sprintf('AxiTrace: upgrade to 1.1.0 failed (%s: %s)', $e::class, $e->getMessage()), 3, null, 'Axitrace', null, true);

        return false;
    }

    return true;
}
