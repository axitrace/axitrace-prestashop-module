<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Domain\ShopHosts;

/**
 * Reads the main, active shop URL of every active shop (`shop_url.main = 1`). Alias URLs are not
 * claimed: PrestaShop redirects them to the main URL, which is where the storefront and its
 * browser events run.
 */
final class PrestaShopStoreHostsReader implements StoreHostsReaderInterface
{
    public function __construct(private readonly DbGatewayInterface $db)
    {
    }

    public function shops(): array
    {
        $prefix = $this->db->prefix();
        $rows = $this->db->select(sprintf(
            'SELECT s.`id_shop`, s.`id_shop_group`, su.`domain`, su.`domain_ssl`'
            . ' FROM `%1$sshop` s INNER JOIN `%1$sshop_url` su ON su.`id_shop` = s.`id_shop` AND su.`main` = 1 AND su.`active` = 1'
            . ' WHERE s.`active` = 1 AND s.`deleted` = 0 ORDER BY s.`id_shop`',
            $prefix,
        ));

        /** @var array<int, array{group: int, hosts: list<string>}> $byShop */
        $byShop = [];
        foreach ($rows as $row) {
            $idShop = (int) ($row['id_shop'] ?? 0);
            if ($idShop <= 0) {
                continue;
            }
            $byShop[$idShop] ??= ['group' => (int) ($row['id_shop_group'] ?? 0), 'hosts' => []];
            foreach ([$row['domain_ssl'] ?? '', $row['domain'] ?? ''] as $domain) {
                $byShop[$idShop]['hosts'][] = is_scalar($domain) ? (string) $domain : '';
            }
        }

        $shops = [];
        foreach ($byShop as $idShop => $shop) {
            $shops[] = ShopHosts::fromDomains($shop['group'], $idShop, $shop['hosts']);
        }

        return $shops;
    }
}
