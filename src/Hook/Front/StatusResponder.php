<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Hook\Front;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Exclusion\CustomerGroupExclusion;

/**
 * Answer of the uncached status controller (/module/axitrace/status): may the browser SDK start
 * for the current visitor? A boolean only, never the group or anything else about the customer.
 */
final class StatusResponder
{
    public const HEADERS = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store, private, max-age=0',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly CustomerGroupExclusion $exclusion,
    ) {
    }

    /**
     * @param int|null $defaultGroupId default group of the logged-in customer, null for a guest
     */
    public function isTracked(?int $defaultGroupId, ShopScope $scope): bool
    {
        return $this->config->isEnabled($scope) && !$this->exclusion->excludes($defaultGroupId, $scope);
    }

    public function body(?int $defaultGroupId, ShopScope $scope): string
    {
        return StorefrontJson::encode(['tracked' => $this->isTracked($defaultGroupId, $scope)]);
    }
}
