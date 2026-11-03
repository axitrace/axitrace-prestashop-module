<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Retry;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\DeferredTaskSchedulerInterface;
use Axitrace\PrestaShop\Adapter\LoggerInterface;

/**
 * Retries without a system cron (A25): ordinary storefront (`displayHeader`) and back-office
 * (`actionAdminControllerSetMedia`) requests run a small batch at their end, but only when
 * AXITRACE_RETRY_DUE_AT says something is due - a request with nothing to do costs one cached
 * configuration read.
 *
 * Storefront requests run the batch only when the response can be finished first (PHP-FPM's
 * fastcgi_finish_request()): a shopper never waits for a retry. Back-office requests also run it
 * without FastCGI, at shutdown. One run at a time ({@see RetryLock}); 20 rows, 1.5 s budget.
 */
final class LazyTrigger
{
    public function __construct(
        private readonly FailedEventQueue $queue,
        private readonly RetryLock $lock,
        private readonly RetryRunner $runner,
        private readonly DeferredTaskSchedulerInterface $scheduler,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param bool $storefront true for a storefront request, false for a back-office one
     *
     * @return bool true when a run was scheduled for the end of this request
     */
    public function maybeSchedule(bool $storefront): bool
    {
        $dueAt = $this->queue->dueAt();
        if ($dueAt === null || $dueAt > $this->clock->now()->getTimestamp()) {
            return false;
        }

        if ($storefront && !$this->scheduler->canFinishResponseFirst()) {
            return false;
        }

        $this->scheduler->defer(function (): void {
            $this->runLocked(RetryRunner::LAZY_BATCH, RetryRunner::LAZY_BUDGET_SECONDS);
        });

        return true;
    }

    /**
     * Runs one batch under the lock. Returns null when another run holds the lock. Never throws.
     */
    public function runLocked(int $batch, float $budgetSeconds): ?RetryReport
    {
        try {
            if (!$this->lock->acquire($this->clock->now())) {
                return null;
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('the retry lock could not be taken (%s: %s)', $e::class, $e->getMessage()));

            return null;
        }

        try {
            return $this->runner->run($batch, $budgetSeconds);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('the retry run failed (%s: %s)', $e::class, $e->getMessage()));

            return new RetryReport();
        } finally {
            try {
                $this->lock->release();
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('the retry lock could not be released (%s: %s); it expires after %d s', $e::class, $e->getMessage(), RetryLock::TTL_SECONDS));
            }
        }
    }
}
