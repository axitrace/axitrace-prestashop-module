<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class PrestaShopDbGateway implements DbGatewayInterface
{
    public function prefix(): string
    {
        return _DB_PREFIX_;
    }

    public function engine(): string
    {
        return defined('_MYSQL_ENGINE_') ? (string) constant('_MYSQL_ENGINE_') : 'InnoDB';
    }

    public function execute(string $sql): void
    {
        $db = \Db::getInstance();
        if (!$db->execute($sql)) {
            throw new \RuntimeException(sprintf('Database statement failed: %s', $db->getMsgError()));
        }
    }

    public function update(string $sql): int
    {
        $db = \Db::getInstance();
        if (!$db->execute($sql)) {
            throw new \RuntimeException(sprintf('Database statement failed: %s', $db->getMsgError()));
        }

        return (int) $db->Affected_Rows();
    }

    public function select(string $sql): array
    {
        $db = \Db::getInstance();
        $rows = $db->executeS($sql);
        if ($rows === false) {
            throw new \RuntimeException(sprintf('Database query failed: %s', $db->getMsgError()));
        }

        $result = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $result[] = $row;
            }
        }

        return $result;
    }

    public function escape(string $value): string
    {
        return \pSQL($value);
    }

    public function escapeLike(string $value): string
    {
        return addcslashes(\pSQL($value), '%_');
    }
}
