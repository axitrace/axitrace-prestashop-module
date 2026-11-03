<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;

/** Records statements; every SHOW COLUMNS finds the column (an install that is up to date). */
final class RecordingDbWithColumns implements DbGatewayInterface
{
    /** @var list<string> */
    public array $executed = [];

    public function __construct(private readonly string $prefix = 'ps_')
    {
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function engine(): string
    {
        return 'InnoDB';
    }

    public function execute(string $sql): void
    {
        $this->executed[] = $sql;
    }

    public function update(string $sql): int
    {
        $this->executed[] = $sql;

        return 1;
    }

    public function select(string $sql): array
    {
        return str_starts_with($sql, 'SHOW COLUMNS') ? [['Field' => 'x']] : [];
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function escapeLike(string $value): string
    {
        return addcslashes(addslashes($value), '%_');
    }
}
