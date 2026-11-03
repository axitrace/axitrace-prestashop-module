<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;

final class RecordingDb implements DbGatewayInterface
{
    /** @var list<string> */
    public array $executed = [];

    public function __construct(private readonly string $prefix = 'ps_', private readonly string $engine = 'InnoDB')
    {
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function engine(): string
    {
        return $this->engine;
    }

    public function execute(string $sql): void
    {
        $this->executed[] = $sql;
    }

    public function select(string $sql): array
    {
        return [];
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
