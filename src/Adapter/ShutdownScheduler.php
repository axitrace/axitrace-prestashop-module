<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * register_shutdown_function(), preceded by fastcgi_finish_request() under PHP-FPM so the visitor
 * already has the page while the task runs. Without FastCGI (Apache mod_php, CLI) the task runs
 * before the connection closes; the lazy trigger only uses that path for back-office requests.
 */
final class ShutdownScheduler implements DeferredTaskSchedulerInterface
{
    private bool $registered = false;

    /** @var list<callable> */
    private array $tasks = [];

    public function canFinishResponseFirst(): bool
    {
        return function_exists('fastcgi_finish_request') && \PHP_SAPI === 'fpm-fcgi';
    }

    public function defer(callable $task): void
    {
        $this->tasks[] = $task;
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        register_shutdown_function(function (): void {
            if ($this->canFinishResponseFirst()) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            foreach ($this->tasks as $task) {
                $task();
            }
        });
    }
}
