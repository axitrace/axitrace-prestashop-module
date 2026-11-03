<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class ProcessEnvironment implements EnvironmentInterface
{
    public function isDevMode(): bool
    {
        return defined('_PS_MODE_DEV_') && constant('_PS_MODE_DEV_') === true;
    }

    public function env(string $name): ?string
    {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
