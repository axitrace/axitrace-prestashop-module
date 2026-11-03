<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\ClockInterface;

/** Every read advances the time: a slow network as the retry runner's time budget sees it. */
final class TickingClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable $start, private readonly float $secondsPerRead)
    {
        $this->now = $start;
    }

    public function now(): \DateTimeImmutable
    {
        $current = $this->now;
        $this->now = $this->now->modify(sprintf('+%d microseconds', (int) round($this->secondsPerRead * 1_000_000)));

        return $current;
    }
}
