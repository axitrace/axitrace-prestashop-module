<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\ClockInterface;

final class MutableClock implements ClockInterface
{
    public function __construct(public \DateTimeImmutable $now = new \DateTimeImmutable('2026-11-03T12:00:00+00:00'))
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
