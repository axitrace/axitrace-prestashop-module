<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Domain;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Config\ShopScope;

/**
 * One shop and the bare storefront hosts it is reached on (lower-case, no scheme, port or path).
 */
final class ShopHosts
{
    /**
     * @param list<string> $hosts normalized and unique, see {@see normalizeHost()}
     */
    public function __construct(
        public readonly int $idShopGroup,
        public readonly int $idShop,
        public readonly array $hosts,
    ) {
    }

    /**
     * @param list<string> $domains ShopUrl domain values (may carry a port)
     */
    public static function fromDomains(int $idShopGroup, int $idShop, array $domains): self
    {
        $hosts = [];
        foreach ($domains as $domain) {
            $host = self::normalizeHost($domain);
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return new self($idShopGroup, $idShop, $hosts);
    }

    public function scope(): ShopScope
    {
        return ShopScope::shop($this->idShopGroup, $this->idShop);
    }

    /**
     * The bare host of a ShopUrl domain or an HTTP Host header: lower-case, without port, path or
     * trailing dot; '' when it is not a host name AxiTrace could accept.
     */
    public static function normalizeHost(string $domain): string
    {
        $host = strtolower(trim($domain));
        $host = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $host);
        $host = explode('/', $host, 2)[0];
        $host = rtrim(explode(':', $host, 2)[0], '.');

        return $host !== '' && strlen($host) <= 253 && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) === 1 ? $host : '';
    }
}
