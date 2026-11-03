<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\DeferredTaskSchedulerInterface;

/** Collects deferred tasks; {@see runDeferred()} plays the end of the request. */
final class FakeScheduler implements DeferredTaskSchedulerInterface
{
    /** @var list<callable> */
    public array $tasks = [];

    public function __construct(public bool $fastCgi = true)
    {
    }

    public function canFinishResponseFirst(): bool
    {
        return $this->fastCgi;
    }

    public function defer(callable $task): void
    {
        $this->tasks[] = $task;
    }

    public function runDeferred(): void
    {
        $tasks = $this->tasks;
        $this->tasks = [];
        foreach ($tasks as $task) {
            $task();
        }
    }
}
