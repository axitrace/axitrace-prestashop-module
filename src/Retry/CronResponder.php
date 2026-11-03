<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Retry;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The cron URL (`/module/axitrace/cron?token=...`): a wrong or missing token is 403 (the front
 * controller compares it with hash_equals()), the right one runs a batch of
 * {@see RetryRunner::CRON_BATCH} rows and answers 200 with a plain-text summary.
 */
final class CronResponder
{
    public const HEADERS = [
        'Content-Type' => 'text/plain; charset=utf-8',
        'Cache-Control' => 'no-store, private, max-age=0',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    public function __construct(private readonly LazyTrigger $trigger)
    {
    }

    /**
     * @param bool $authorized the request's token equals AXITRACE_CRON_TOKEN (non-empty)
     *
     * @return array{status: int, body: string}
     */
    public function respond(bool $authorized): array
    {
        if (!$authorized) {
            return ['status' => 403, 'body' => "Forbidden\n"];
        }

        $report = $this->trigger->runLocked(RetryRunner::CRON_BATCH, RetryRunner::CRON_BUDGET_SECONDS);
        if ($report === null) {
            return ['status' => 200, 'body' => "processed=0 locked=1\n"];
        }

        return ['status' => 200, 'body' => $report->summary() . "\n"];
    }
}
