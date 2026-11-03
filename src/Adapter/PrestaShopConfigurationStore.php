<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class PrestaShopConfigurationStore implements ConfigurationStoreInterface
{
    public function __construct(private readonly DbGatewayInterface $db)
    {
    }

    public function get(string $key, ?int $idShopGroup = null, ?int $idShop = null): ?string
    {
        $value = \Configuration::get($key, null, $idShopGroup, $idShop, false);

        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }

    public function set(string $key, string $value, ?int $idShopGroup = null, ?int $idShop = null): void
    {
        if (!\Configuration::updateValue($key, $value, false, $idShopGroup, $idShop)) {
            throw new \RuntimeException(sprintf('Configuration value %s could not be saved.', $key));
        }
    }

    public function setGlobal(string $key, string $value): void
    {
        if (!\Configuration::updateGlobalValue($key, $value)) {
            throw new \RuntimeException(sprintf('Configuration value %s could not be saved.', $key));
        }
    }

    public function delete(string $key): void
    {
        \Configuration::deleteByName($key);
    }

    public function namesWithPrefix(string $prefix): array
    {
        $rows = $this->db->select(sprintf(
            "SELECT DISTINCT `name` FROM `%sconfiguration` WHERE `name` LIKE '%s%%'",
            $this->db->prefix(),
            $this->db->escapeLike($prefix),
        ));

        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) $row['name'];
        }

        return $names;
    }
}
