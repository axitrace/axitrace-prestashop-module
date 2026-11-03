<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class DnsHostResolver implements HostResolverInterface
{
    public function resolve(string $host): array
    {
        $ips = gethostbynamel($host);

        return $ips === false ? [] : $ips;
    }
}
