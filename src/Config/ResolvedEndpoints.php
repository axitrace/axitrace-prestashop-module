<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class ResolvedEndpoints
{
    public function __construct(
        public readonly string $apiBaseUrl,
        public readonly string $sdkBaseUrl,
        public readonly ?EndpointError $error = null,
        public readonly string $rejectedDomain = '',
        public readonly bool $overrideActive = false,
    ) {
    }
}
