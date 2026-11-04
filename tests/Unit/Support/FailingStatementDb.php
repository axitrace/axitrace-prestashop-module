<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;

/** Runs everything on an {@see InMemoryModuleDb} but fails every statement containing one of the needles. */
final class FailingStatementDb implements DbGatewayInterface
{
    public int $failures = 0;

    /** @var list<string> */
    private readonly array $needles;

    public function __construct(private readonly InMemoryModuleDb $db, string ...$needles)
    {
        $this->needles = array_values($needles);
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
        $this->guard($sql);
        $this->db->execute($sql);
    }

    public function update(string $sql): int
    {
        $this->guard($sql);

        return $this->db->update($sql);
    }

    public function select(string $sql): array
    {
        $this->guard($sql);

        return $this->db->select($sql);
    }

    public function escape(string $value): string
    {
        return $this->db->escape($value);
    }

    public function escapeRaw(string $value): string
    {
        return $this->db->escapeRaw($value);
    }

    public function escapeLike(string $value): string
    {
        return $this->db->escapeLike($value);
    }

    private function guard(string $sql): void
    {
        foreach ($this->needles as $needle) {
            if (str_contains($sql, $needle)) {
                ++$this->failures;

                throw new \RuntimeException('Database statement failed: Deadlock found when trying to get lock');
            }
        }
    }
}
