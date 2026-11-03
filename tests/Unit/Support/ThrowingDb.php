<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;

final class ThrowingDb implements DbGatewayInterface
{
    public function prefix(): string
    {
        return 'ps_';
    }

    public function engine(): string
    {
        return 'InnoDB';
    }

    public function execute(string $sql): void
    {
        throw new \RuntimeException('Database statement failed: Lost connection');
    }

    public function select(string $sql): array
    {
        throw new \RuntimeException('Database query failed: Lost connection');
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function escapeLike(string $value): string
    {
        return addslashes($value);
    }
}
