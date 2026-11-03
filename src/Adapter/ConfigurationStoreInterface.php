<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reads and writes PrestaShop Configuration values. A null shop group / shop id means "the current
 * shop context" (PrestaShop's own rule), so the back office saves into whatever context the merchant
 * picked in the shop selector.
 */
interface ConfigurationStoreInterface
{
    /** Returns null when the key is not set at all for the scope (or its parents). */
    public function get(string $key, ?int $idShopGroup = null, ?int $idShop = null): ?string;

    public function set(string $key, string $value, ?int $idShopGroup = null, ?int $idShop = null): void;

    public function setGlobal(string $key, string $value): void;

    /** Deletes the key for every scope. */
    public function delete(string $key): void;

    /**
     * Names of every stored key starting with the given prefix, whatever the scope.
     *
     * @return list<string>
     */
    public function namesWithPrefix(string $prefix): array;
}
