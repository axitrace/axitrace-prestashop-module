<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Retry;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Counts of one retry run, printed by the cron URL. */
final class RetryReport
{
    public function __construct(
        public readonly int $processed = 0,
        public readonly int $delivered = 0,
        public readonly int $failed = 0,
        public readonly int $dead = 0,
        public readonly int $dropped = 0,
        public readonly bool $budgetExhausted = false,
    ) {
    }

    public function summary(): string
    {
        return sprintf(
            'processed=%d delivered=%d failed=%d dead=%d dropped=%d%s',
            $this->processed,
            $this->delivered,
            $this->failed,
            $this->dead,
            $this->dropped,
            $this->budgetExhausted ? ' budget_exhausted=1' : '',
        );
    }
}
