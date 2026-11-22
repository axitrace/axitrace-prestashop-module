<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Domain\ShopHosts;

/**
 * The storefront hosts of every active shop: the main shop URL's domain and SSL domain (multistore
 * ShopUrl), the hosts browsers load the storefront from.
 */
interface StoreHostsReaderInterface
{
    /**
     * @return list<ShopHosts> ordered by shop id
     */
    public function shops(): array;
}
