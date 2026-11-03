<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface EnvironmentInterface
{
    /** True only when PrestaShop runs with `_PS_MODE_DEV_ === true`. */
    public function isDevMode(): bool;

    /** The process environment variable, or null when unset or empty. */
    public function env(string $name): ?string;
}
