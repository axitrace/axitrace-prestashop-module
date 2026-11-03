<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface HostResolverInterface
{
    /**
     * IPv4 addresses of the host; an empty list when it does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
