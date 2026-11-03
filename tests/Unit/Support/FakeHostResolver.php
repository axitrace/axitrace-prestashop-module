<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\HostResolverInterface;

final class FakeHostResolver implements HostResolverInterface
{
    /** @var list<string> */
    public array $lookups = [];

    /**
     * @param array<string, list<string>> $records
     */
    public function __construct(private readonly array $records = [])
    {
    }

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        return $this->records[$host] ?? [];
    }
}
