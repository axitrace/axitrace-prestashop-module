<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Runs work after the current request, without making the visitor wait when the SAPI allows it. */
interface DeferredTaskSchedulerInterface
{
    /** True when the response can be sent to the visitor before the deferred work (PHP-FPM). */
    public function canFinishResponseFirst(): bool;

    /** Runs the task at the end of the request, after the response when {@see canFinishResponseFirst()}. */
    public function defer(callable $task): void;
}
