<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;

/**
 * Configuration with PrestaShop's inheritance: shop -> shop group -> global. The "current context"
 * (null ids) is the shop/group set with {@see useContext()}. Like PrestaShop (an empty value is
 * stored as NULL and skipped by isset()), an empty value does not stop inheritance.
 */
final class InMemoryConfigurationStore implements ConfigurationStoreInterface
{
    /** @var array<string, string> keyed by "<group>:<shop>:<key>" ("0:0" = global) */
    public array $values = [];

    private int $contextGroup = 0;
    private int $contextShop = 0;

    public function useContext(int $idShopGroup, int $idShop): void
    {
        $this->contextGroup = $idShopGroup;
        $this->contextShop = $idShop;
    }

    public function get(string $key, ?int $idShopGroup = null, ?int $idShop = null): ?string
    {
        $group = $idShopGroup ?? $this->contextGroup;
        $shop = $idShop ?? $this->contextShop;

        foreach ([[$group, $shop], [$group, 0], [0, 0]] as [$g, $s]) {
            $id = $g . ':' . $s . ':' . $key;
            if (isset($this->values[$id]) && $this->values[$id] !== '') {
                return $this->values[$id];
            }
        }

        return null;
    }

    public function set(string $key, string $value, ?int $idShopGroup = null, ?int $idShop = null): void
    {
        $group = $idShopGroup ?? $this->contextGroup;
        $shop = $idShop ?? $this->contextShop;
        $this->values[$group . ':' . $shop . ':' . $key] = $value;
    }

    public function setGlobal(string $key, string $value): void
    {
        $this->values['0:0:' . $key] = $value;
    }

    public function delete(string $key): void
    {
        foreach (array_keys($this->values) as $id) {
            if (str_ends_with($id, ':' . $key)) {
                unset($this->values[$id]);
            }
        }
    }

    public function namesWithPrefix(string $prefix): array
    {
        $names = [];
        foreach (array_keys($this->values) as $id) {
            $name = explode(':', $id, 3)[2];
            if (str_starts_with($name, $prefix)) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
