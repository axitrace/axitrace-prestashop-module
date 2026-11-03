<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface ShopGroupResolverInterface
{
    /** The shop group of a shop (configuration is read per group and shop). */
    public function groupOf(int $idShop): int;
}
