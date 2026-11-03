<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\EnvironmentInterface;

final class FakeEnvironment implements EnvironmentInterface
{
    /**
     * @param array<string, string> $env
     */
    public function __construct(private readonly bool $devMode = false, private readonly array $env = [])
    {
    }

    public function isDevMode(): bool
    {
        return $this->devMode;
    }

    public function env(string $name): ?string
    {
        return $this->env[$name] ?? null;
    }
}
