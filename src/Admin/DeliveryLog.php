<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\TranslatorInterface;
use Axitrace\PrestaShop\Retry\FailedEventQueue;
use Axitrace\PrestaShop\Retry\LazyTrigger;
use Axitrace\PrestaShop\Retry\RetryReport;
use Axitrace\PrestaShop\Retry\RetryRunner;

/**
 * "Delivery log" on the configuration page: the newest {@see LIMIT} rows of the retry queue,
 * queued, parked and failed, and the "Retry now" action, which makes every queued and parked row
 * due and runs one batch at once under the retry lock (a back-office POST checked against the
 * module's action token by the caller).
 */
final class DeliveryLog
{
    public const LIMIT = 50;
    public const SUBMIT_RETRY = 'submitAxitraceRetryNow';

    /** "Retry now" runs in a back-office request: a bounded batch, never the whole cron budget. */
    public const RETRY_NOW_BUDGET_SECONDS = 10.0;

    public function __construct(
        private readonly FailedEventQueue $queue,
        private readonly LazyTrigger $trigger,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array{event_id: string, type: string, status: string, failed: bool, attempts: int, next_attempt_at: string, last_error: string, created_at: string}>
     */
    public function entries(): array
    {
        $t = $this->translator;
        $d = 'Modules.Axitrace.Admin';

        $entries = [];
        foreach ($this->queue->recent(self::LIMIT) as $event) {
            $queued = $event->status === FailedEventQueue::STATUS_QUEUED;
            $entries[] = [
                'event_id' => $event->eventId,
                'type' => $event->endpoint === FailedEventQueue::ENDPOINT_REFUND ? $t->trans('Refund', [], $d) : $t->trans('Purchase', [], $d),
                'status' => match ($event->status) {
                    FailedEventQueue::STATUS_QUEUED => $t->trans('Waiting for a retry', [], $d),
                    FailedEventQueue::STATUS_PARKED => $t->trans('Waiting for a valid secret key: enter it, then click Retry now', [], $d),
                    default => $t->trans('Failed', [], $d),
                },
                'failed' => !$queued,
                'attempts' => $event->attempts,
                'next_attempt_at' => $queued ? $event->nextAttemptAt : '',
                'last_error' => $event->lastError,
                'created_at' => $event->createdAt,
            ];
        }

        return $entries;
    }

    /**
     * Queued rows and refunds parked for the secret key are sent at once. Null when another retry
     * run holds the lock.
     */
    public function retryNow(): ?RetryReport
    {
        $counts = $this->queue->counts();
        if ($counts['queued'] + $counts['parked'] === 0) {
            return new RetryReport();
        }

        $this->queue->makeQueuedDue($this->clock->now());

        return $this->trigger->runLocked(RetryRunner::CRON_BATCH, self::RETRY_NOW_BUDGET_SECONDS);
    }

    /** The confirmation shown after "Retry now". */
    public function summary(?RetryReport $report): string
    {
        $t = $this->translator;
        $d = 'Modules.Axitrace.Admin';

        if ($report === null) {
            return $t->trans('Another retry run is in progress. Try again in a minute.', [], $d);
        }
        if ($report->processed === 0) {
            return $t->trans('Nothing is waiting for a retry.', [], $d);
        }

        return $t->trans('Retried %processed% deliveries: %delivered% delivered, %failed% still failing, %dead% failed for good, %parked% waiting for a valid secret key.', [
            '%processed%' => $report->processed,
            '%delivered%' => $report->delivered,
            '%failed%' => $report->failed,
            '%dead%' => $report->dead,
            '%parked%' => $report->parked,
        ], $d);
    }
}
