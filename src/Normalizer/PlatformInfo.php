<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Normalizer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Versions reported with every purchase (A14: payload only, no panel reader). */
final class PlatformInfo
{
    public function __construct(
        public readonly string $pluginVersion,
        public readonly string $psVersion,
        public readonly string $phpVersion,
    ) {
    }
}
