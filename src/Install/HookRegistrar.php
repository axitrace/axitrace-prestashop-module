<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Every hook the module uses, registered at install so the module needs no upgrade script while
 * features are added. Axitrace declares a method for each of them.
 */
final class HookRegistrar
{
    public const HOOKS = [
        // Storefront (T6, T7, T9)
        'displayHeader',
        'displayFooterProduct',
        'displayOrderConfirmation',
        'actionFrontControllerSetMedia',
        // Back office (T9 lazy retry trigger)
        'actionAdminControllerSetMedia',
        // Orders (T7, T8, T10)
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionOrderSlipAdd',
        'actionProductCancel',
        // psgdpr (T11)
        'registerGDPRConsent',
        'actionExportGDPRData',
        'actionDeleteGDPRCustomer',
    ];

    /**
     * @param callable(string): bool $registerHook PrestaShop's Module::registerHook
     *
     * @return list<string> hooks that failed to register (empty on success)
     */
    public function register(callable $registerHook): array
    {
        $failed = [];
        foreach (self::HOOKS as $hook) {
            if (!$registerHook($hook)) {
                $failed[] = $hook;
            }
        }

        return $failed;
    }

    /** Name of the module method PrestaShop calls for a hook. */
    public static function methodName(string $hook): string
    {
        return 'hook' . ucfirst($hook);
    }
}
