<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Exclusion;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;

/**
 * "Customer groups excluded from tracking": a customer is excluded when their DEFAULT customer
 * group is one of the configured groups. Shared by the storefront status controller and the
 * server-side order pipeline.
 */
final class CustomerGroupExclusion
{
    public function __construct(private readonly ModuleConfig $config)
    {
    }

    public function isConfigured(ShopScope $scope): bool
    {
        return $this->config->excludedGroupIds($scope) !== [];
    }

    /** A visitor who is not logged in (null group) is never excluded: they cannot be recognised. */
    public function excludes(?int $defaultGroupId, ShopScope $scope): bool
    {
        if ($defaultGroupId === null || $defaultGroupId <= 0) {
            return false;
        }

        return in_array($defaultGroupId, $this->config->excludedGroupIds($scope), true);
    }
}
