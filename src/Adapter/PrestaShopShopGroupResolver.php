<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class PrestaShopShopGroupResolver implements ShopGroupResolverInterface
{
    public function groupOf(int $idShop): int
    {
        return $idShop > 0 ? (int) \Shop::getGroupFromShop($idShop, true) : 0;
    }
}
