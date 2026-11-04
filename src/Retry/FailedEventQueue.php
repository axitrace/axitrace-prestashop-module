<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Retry;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;
use Axitrace\PrestaShop\Adapter\DbGatewayInterface;
use Axitrace\PrestaShop\Config\ModuleConfig;

/**
 * Table `axitrace_failed_event`: deliveries that did not reach AxiTrace (A25).
 *
 * A row holds the request body exactly as it was built (same event id, so a resend deduplicates),
 * the endpoint and the shop it belongs to - never the secret key, which is read again from that
 * shop's configuration at every attempt.
 *
 * Schedule: the first attempt is the one that failed, so a new row waits 5 minutes; every further
 * failure doubles the wait, capped at 6 hours: min(5 min x 2^(n-1), 6 h) after the n-th failed
 * attempt. After {@see MAX_ATTEMPTS} attempts, or at once for a request AxiTrace refused (400, 413,
 * 415, unknown public key), the row is `dead`: kept {@see DEAD_RETENTION_DAYS} days for the delivery
 * log, then pruned. A delivered row is deleted.
 *
 * A refund AxiTrace refused because of the secret key is `parked`: never retried on its own (the
 * same key gets the same answer), never pruned, listed in the delivery log, and queued again by
 * "Retry now" ({@see makeQueuedDue()}) once the merchant has entered a valid key.
 *
 * AXITRACE_RETRY_DUE_AT (global, Unix time) holds the earliest due row so the lazy trigger can
 * decide without a query whether anything is due.
 */
final class FailedEventQueue
{
    public const ENDPOINT_PIXEL = 'pixel';
    public const ENDPOINT_REFUND = 'refund';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_DEAD = 'dead';
    public const STATUS_PARKED = 'parked';

    public const MAX_ATTEMPTS = 10;
    public const BASE_DELAY_SECONDS = 300;
    public const MAX_DELAY_SECONDS = 21600;
    public const DEAD_RETENTION_DAYS = 30;

    private const MAX_ERROR_LENGTH = 512;

    public function __construct(
        private readonly DbGatewayInterface $db,
        private readonly ConfigurationStoreInterface $store,
    ) {
    }

    /** Wait after the n-th failed attempt (n >= 1): min(5 min x 2^(n-1), 6 h). */
    public static function backoffSeconds(int $failedAttempts): int
    {
        $n = max(1, $failedAttempts);
        if ($n > 12) {
            return self::MAX_DELAY_SECONDS;
        }

        return min(self::BASE_DELAY_SECONDS * (2 ** ($n - 1)), self::MAX_DELAY_SECONDS);
    }

    /**
     * Queues a request whose first attempt failed. A request already queued under the same event
     * id and endpoint is not queued twice.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $eventId, string $endpoint, int $idShop, array $payload, string $error, \DateTimeImmutable $now): void
    {
        if ($this->isQueued($eventId, $endpoint)) {
            return;
        }

        $next = $now->modify(sprintf('+%d seconds', self::backoffSeconds(1)));
        $this->insert($eventId, $endpoint, $idShop, $payload, 1, $next, $error, self::STATUS_QUEUED, $now);
        $this->markDueBy($next);
    }

    /**
     * Records a request AxiTrace refused for good, for the delivery log; it is never retried.
     *
     * @param array<string, mixed> $payload
     */
    public function addDead(string $eventId, string $endpoint, int $idShop, array $payload, string $error, \DateTimeImmutable $now): void
    {
        $this->insert($eventId, $endpoint, $idShop, $payload, 1, $now, $error, self::STATUS_DEAD, $now);
    }

    /**
     * Parks a request AxiTrace refused because of the secret key (see the class comment).
     *
     * @param array<string, mixed> $payload
     */
    public function park(string $eventId, string $endpoint, int $idShop, array $payload, string $error, \DateTimeImmutable $now): void
    {
        $this->insert($eventId, $endpoint, $idShop, $payload, 1, $now, $error, self::STATUS_PARKED, $now);
    }

    /** Parks a queued row whose retry was refused because of the secret key. */
    public function markParked(FailedEvent $event, string $error, \DateTimeImmutable $now): void
    {
        $this->db->update(sprintf(
            "UPDATE `%saxitrace_failed_event` SET `attempts` = %d, `next_attempt_at` = '%s', `last_error` = '%s', `status` = '%s' WHERE `id` = %d",
            $this->db->prefix(),
            $event->attempts + 1,
            self::utc($now),
            $this->db->escape(self::bounded($error)),
            self::STATUS_PARKED,
            $event->id,
        ));
    }

    /**
     * Queued rows due now, oldest due first.
     *
     * @return list<FailedEvent>
     */
    public function due(\DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->db->select(sprintf(
            "SELECT * FROM `%saxitrace_failed_event` WHERE `status` = '%s' AND `next_attempt_at` <= '%s' ORDER BY `next_attempt_at`, `id` LIMIT %d",
            $this->db->prefix(),
            self::STATUS_QUEUED,
            self::utc($now),
            max(1, $limit),
        ));

        return array_map(FailedEvent::fromRow(...), $rows);
    }

    public function delete(int $id): void
    {
        $this->db->update(sprintf('DELETE FROM `%saxitrace_failed_event` WHERE `id` = %d', $this->db->prefix(), $id));
    }

    /**
     * Counts one more failed attempt: reschedules the row with the backoff, or marks it dead after
     * {@see MAX_ATTEMPTS}. Returns true when the row is now dead.
     */
    public function recordFailure(FailedEvent $event, string $error, \DateTimeImmutable $now): bool
    {
        $attempts = $event->attempts + 1;
        $dead = $attempts >= self::MAX_ATTEMPTS;

        $this->db->update(sprintf(
            "UPDATE `%saxitrace_failed_event` SET `attempts` = %d, `next_attempt_at` = '%s', `last_error` = '%s', `status` = '%s' WHERE `id` = %d",
            $this->db->prefix(),
            $attempts,
            self::utc($dead ? $now : $now->modify(sprintf('+%d seconds', self::backoffSeconds($attempts)))),
            $this->db->escape(self::bounded($error)),
            $dead ? self::STATUS_DEAD : self::STATUS_QUEUED,
            $event->id,
        ));

        return $dead;
    }

    /** Marks a row dead at once (the request was refused; a retry would get the same answer). */
    public function markDead(FailedEvent $event, string $error, \DateTimeImmutable $now): void
    {
        $this->db->update(sprintf(
            "UPDATE `%saxitrace_failed_event` SET `attempts` = %d, `next_attempt_at` = '%s', `last_error` = '%s', `status` = '%s' WHERE `id` = %d",
            $this->db->prefix(),
            $event->attempts + 1,
            self::utc($now),
            $this->db->escape(self::bounded($error)),
            self::STATUS_DEAD,
            $event->id,
        ));
    }

    /** Deletes dead rows whose last attempt is older than {@see DEAD_RETENTION_DAYS} days. */
    public function pruneDead(\DateTimeImmutable $now): void
    {
        $this->db->update(sprintf(
            "DELETE FROM `%saxitrace_failed_event` WHERE `status` = '%s' AND `next_attempt_at` < '%s'",
            $this->db->prefix(),
            self::STATUS_DEAD,
            self::utc($now->modify(sprintf('-%d days', self::DEAD_RETENTION_DAYS))),
        ));
    }

    /** Recomputes AXITRACE_RETRY_DUE_AT from the table: the earliest queued row, or '' when none. */
    public function refreshDueAt(): void
    {
        $rows = $this->db->select(sprintf(
            "SELECT MIN(`next_attempt_at`) AS `due` FROM `%saxitrace_failed_event` WHERE `status` = '%s'",
            $this->db->prefix(),
            self::STATUS_QUEUED,
        ));
        $due = is_string($rows[0]['due'] ?? null) ? $rows[0]['due'] : '';

        $this->store->setGlobal(ModuleConfig::RETRY_DUE_AT, $due === '' ? '' : (string) self::fromUtc($due)->getTimestamp());
    }

    /** The Unix time the earliest queued row is due, or null when nothing is queued. */
    public function dueAt(): ?int
    {
        $raw = trim((string) $this->store->get(ModuleConfig::RETRY_DUE_AT));

        return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /** Lowers AXITRACE_RETRY_DUE_AT to $when when that is earlier than the stored value. */
    public function markDueBy(\DateTimeImmutable $when): void
    {
        $current = $this->dueAt();
        if ($current === null || $when->getTimestamp() < $current) {
            $this->store->setGlobal(ModuleConfig::RETRY_DUE_AT, (string) $when->getTimestamp());
        }
    }

    /**
     * The newest rows, queued and dead, for the back-office delivery log.
     *
     * @return list<FailedEvent>
     */
    public function recent(int $limit): array
    {
        return array_map(FailedEvent::fromRow(...), $this->db->select(sprintf(
            'SELECT * FROM `%saxitrace_failed_event` ORDER BY `id` DESC LIMIT %d',
            $this->db->prefix(),
            max(1, $limit),
        )));
    }

    /**
     * Number of rows per status.
     *
     * @return array{queued: int, parked: int, dead: int}
     */
    public function counts(): array
    {
        $counts = [self::STATUS_QUEUED => 0, self::STATUS_PARKED => 0, self::STATUS_DEAD => 0];
        foreach ($this->db->select(sprintf(
            'SELECT `status`, COUNT(*) AS `rows` FROM `%saxitrace_failed_event` GROUP BY `status`',
            $this->db->prefix(),
        )) as $row) {
            $status = is_scalar($row['status'] ?? null) ? (string) $row['status'] : '';
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['rows'];
            }
        }

        return ['queued' => $counts[self::STATUS_QUEUED], 'parked' => $counts[self::STATUS_PARKED], 'dead' => $counts[self::STATUS_DEAD]];
    }

    /**
     * "Retry now": every parked row is queued again ({@see requeueParked()}) and every queued row
     * becomes due at once; returns how many rows changed.
     */
    public function makeQueuedDue(\DateTimeImmutable $now): int
    {
        $requeued = $this->requeueParked($now);
        $changed = $this->db->update(sprintf(
            "UPDATE `%saxitrace_failed_event` SET `next_attempt_at` = '%s' WHERE `status` = '%s'",
            $this->db->prefix(),
            self::utc($now),
            self::STATUS_QUEUED,
        ));
        $this->refreshDueAt();

        return max($requeued, $changed);
    }

    /**
     * Parked rows become queued and due now with a fresh attempt count (the key they were refused
     * with is gone; they get the full retry schedule). Called by "Retry now" and when a secret key
     * is saved. Returns how many.
     */
    public function requeueParked(\DateTimeImmutable $now): int
    {
        $changed = $this->db->update(sprintf(
            "UPDATE `%saxitrace_failed_event` SET `next_attempt_at` = '%s', `attempts` = 0, `status` = '%s' WHERE `status` = '%s'",
            $this->db->prefix(),
            self::utc($now),
            self::STATUS_QUEUED,
            self::STATUS_PARKED,
        ));
        if ($changed > 0) {
            $this->markDueBy($now);
        }

        return $changed;
    }

    /**
     * Every row (queued or dead) of one order: its purchase (by the purchase event id) and its
     * refunds (refund ids `prestashop:<id_order>:...`). For GDPR export and erasure.
     *
     * @return list<FailedEvent>
     */
    public function forOrder(int $idOrder, string $purchaseEventId): array
    {
        return array_map(FailedEvent::fromRow(...), $this->db->select(sprintf(
            'SELECT * FROM `%saxitrace_failed_event` WHERE %s ORDER BY `id`',
            $this->db->prefix(),
            $this->orderCondition($idOrder, $purchaseEventId),
        )));
    }

    /** Deletes every row of one order ({@see forOrder()}); returns how many. */
    public function deleteForOrder(int $idOrder, string $purchaseEventId): int
    {
        $deleted = $this->db->update(sprintf(
            'DELETE FROM `%saxitrace_failed_event` WHERE %s',
            $this->db->prefix(),
            $this->orderCondition($idOrder, $purchaseEventId),
        ));
        $this->refreshDueAt();

        return $deleted;
    }

    private function orderCondition(int $idOrder, string $purchaseEventId): string
    {
        $refunds = sprintf("`event_id` LIKE '%s%%'", $this->db->escapeLike(sprintf('prestashop:%d:', $idOrder)));
        if (trim($purchaseEventId) === '') {
            return $refunds;
        }

        return sprintf("(`event_id` = '%s' OR %s)", $this->db->escape($purchaseEventId), $refunds);
    }

    private function isQueued(string $eventId, string $endpoint): bool
    {
        return $this->db->select(sprintf(
            "SELECT `id` FROM `%saxitrace_failed_event` WHERE `event_id` = '%s' AND `endpoint` = '%s' AND `status` = '%s' LIMIT 1",
            $this->db->prefix(),
            $this->db->escape($eventId),
            $this->db->escape($endpoint),
            self::STATUS_QUEUED,
        )) !== [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insert(string $eventId, string $endpoint, int $idShop, array $payload, int $attempts, \DateTimeImmutable $next, string $error, string $status, \DateTimeImmutable $now): void
    {
        if ($endpoint !== self::ENDPOINT_PIXEL && $endpoint !== self::ENDPOINT_REFUND) {
            throw new \InvalidArgumentException(sprintf('Unknown endpoint "%s".', $endpoint));
        }

        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);

        $this->db->execute(sprintf(
            'INSERT INTO `%saxitrace_failed_event` (`event_id`, `endpoint`, `id_shop`, `payload`, `attempts`, `next_attempt_at`, `last_error`, `status`, `created_at`)'
            . " VALUES ('%s', '%s', %d, '%s', %d, '%s', '%s', '%s', '%s')",
            $this->db->prefix(),
            $this->db->escape(mb_substr($eventId, 0, 64)),
            $endpoint,
            $idShop,
            $this->db->escapeRaw($json),
            $attempts,
            self::utc($next),
            $this->db->escape(self::bounded($error)),
            $status,
            self::utc($now),
        ));
    }

    private static function bounded(string $error): string
    {
        return mb_substr($error, 0, self::MAX_ERROR_LENGTH);
    }

    public static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function fromUtc(string $value): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        return $time !== false ? $time : new \DateTimeImmutable('@0');
    }
}
