<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;

/**
 * A second request racing the first: its reads of `axitrace_order` return the rows as they were
 * when it started, its writes go to the shared tables.
 */
final class StaleOrderReadDb implements DbGatewayInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $ordersAtStart;

    public function __construct(private readonly InMemoryModuleDb $db)
    {
        $this->ordersAtStart = $db->orders;
    }

    public function prefix(): string
    {
        return $this->db->prefix();
    }

    public function engine(): string
    {
        return $this->db->engine();
    }

    public function execute(string $sql): void
    {
        $this->db->execute($sql);
    }

    public function update(string $sql): int
    {
        return $this->db->update($sql);
    }

    public function select(string $sql): array
    {
        if (preg_match('/FROM `ps_axitrace_order` WHERE `id_order` = (\d+)/', $sql, $m) === 1) {
            return isset($this->ordersAtStart[(int) $m[1]]) ? [$this->ordersAtStart[(int) $m[1]]] : [];
        }

        return $this->db->select($sql);
    }

    public function escape(string $value): string
    {
        return $this->db->escape($value);
    }

    public function escapeLike(string $value): string
    {
        return $this->db->escapeLike($value);
    }
}
