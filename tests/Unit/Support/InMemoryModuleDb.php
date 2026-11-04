<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;

/**
 * Executes the module's own statements on `axitrace_cart_snapshot` and `axitrace_order` against
 * in-memory tables, so hook tests can assert on rows instead of SQL strings. Escaping is
 * PrestaShop's ({@see PsqlEscape}: escape() strips tags like pSQL), undone when the values are
 * parsed back.
 */
final class InMemoryModuleDb implements DbGatewayInterface
{
    /** @var array<int, array<string, mixed>> keyed by id_cart */
    public array $cartSnapshots = [];

    /** @var array<int, array<string, mixed>> keyed by id_order */
    public array $orders = [];

    /** @var array<int, array<string, mixed>> keyed by id */
    public array $failedEvents = [];

    /** @var array<int, array<string, mixed>> keyed by id */
    public array $refunds = [];

    /** @var list<string> */
    public array $executed = [];

    /** Configuration table behind the lock statements on `ps_configuration`. */
    public ?InMemoryConfigurationStore $configuration = null;

    private int $nextFailedEventId = 1;

    private int $nextRefundId = 1;

    public function prefix(): string
    {
        return 'ps_';
    }

    public function engine(): string
    {
        return 'InnoDB';
    }

    public function execute(string $sql): void
    {
        $this->executed[] = $sql;

        if (preg_match('/^INSERT INTO `ps_axitrace_cart_snapshot` \(([^)]*)\) VALUES \((.*)\) ON DUPLICATE KEY UPDATE/s', $sql, $m) === 1) {
            $row = $this->row($m[1], $m[2]);
            $this->cartSnapshots[(int) $row['id_cart']] = $row;

            return;
        }

        if (preg_match('/^INSERT IGNORE INTO `ps_axitrace_order` \(([^)]*)\) VALUES \((.*)\)$/s', $sql, $m) === 1) {
            $row = $this->row($m[1], $m[2]);
            $this->orders[(int) $row['id_order']] ??= $row;

            return;
        }

        if (preg_match('/^INSERT INTO `ps_axitrace_failed_event` \(([^)]*)\) VALUES \((.*)\)$/s', $sql, $m) === 1) {
            $row = ['id' => (string) $this->nextFailedEventId] + $this->row($m[1], $m[2]);
            $this->failedEvents[$this->nextFailedEventId++] = $row;

            return;
        }

        if (preg_match('/^DELETE FROM `ps_axitrace_cart_snapshot` WHERE `id_cart` = (\\d+)$/', $sql, $m) === 1) {
            unset($this->cartSnapshots[(int) $m[1]]);

            return;
        }

        if (preg_match("/^DELETE FROM `ps_axitrace_cart_snapshot` WHERE `updated_at` < '([^']+)'$/", $sql, $m) === 1) {
            $this->cartSnapshots = array_filter($this->cartSnapshots, static fn (array $row): bool => $row['updated_at'] >= $m[1]);

            return;
        }

        throw new \LogicException('Statement not supported by the fake: ' . $sql);
    }

    /** @var int number of UPDATE statements that changed no row */
    public int $noOpUpdates = 0;

    public function update(string $sql): int
    {
        $this->executed[] = $sql;

        if (str_contains($sql, '`ps_axitrace_failed_event`')) {
            return $this->updateFailedEvents($sql);
        }
        if (str_contains($sql, '`ps_axitrace_refund`')) {
            return $this->updateRefunds($sql);
        }
        if (str_starts_with($sql, 'UPDATE `ps_configuration`')) {
            return $this->updateConfiguration($sql);
        }

        if (preg_match('/^UPDATE `ps_axitrace_order` SET (.*) WHERE `id_order` = (\d+)(?: AND `(\w+)` IS NULL)?$/s', $sql, $m) !== 1) {
            throw new \LogicException('Statement not supported by the fake: ' . $sql);
        }

        $id = (int) $m[2];
        $guard = $m[3] ?? '';
        if (!isset($this->orders[$id]) || ($guard !== '' && ($this->orders[$id][$guard] ?? null) !== null)) {
            ++$this->noOpUpdates;

            return 0;
        }

        preg_match_all("/`(\w+)` = (?:'((?:[^'\\\\]|\\\\.)*)'|(NULL))/", $m[1], $assignments, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        foreach ($assignments as $assignment) {
            $this->orders[$id][(string) $assignment[1]] = ($assignment[3] ?? null) === 'NULL' ? null : stripslashes((string) $assignment[2]);
        }

        return 1;
    }

    public function select(string $sql): array
    {
        if (preg_match('/FROM `ps_axitrace_cart_snapshot` WHERE `id_cart` = (\d+)/', $sql, $m) === 1) {
            return isset($this->cartSnapshots[(int) $m[1]]) ? [$this->cartSnapshots[(int) $m[1]]] : [];
        }
        if (preg_match('/FROM `ps_axitrace_order` WHERE `id_order` = (\d+)/', $sql, $m) === 1) {
            return isset($this->orders[(int) $m[1]]) ? [$this->orders[(int) $m[1]]] : [];
        }

        if (preg_match('/^SELECT \\* FROM `ps_axitrace_failed_event` ORDER BY `id` DESC LIMIT (\\d+)$/', $sql, $m) === 1) {
            $rows = $this->failedEvents;
            krsort($rows);

            return array_slice(array_values($rows), 0, (int) $m[1]);
        }
        if ($sql === 'SELECT `status`, COUNT(*) AS `rows` FROM `ps_axitrace_failed_event` GROUP BY `status`') {
            $counts = [];
            foreach ($this->failedEvents as $row) {
                $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
            }
            $rows = [];
            foreach ($counts as $status => $count) {
                $rows[] = ['status' => $status, 'rows' => (string) $count];
            }

            return $rows;
        }
        if (preg_match('/^SELECT \\* FROM `ps_axitrace_failed_event` WHERE (.+) ORDER BY `id`$/', $sql, $m) === 1) {
            return array_values(array_filter($this->failedEvents, $this->orderCondition($m[1])));
        }

        if (preg_match('/^SELECT \\* FROM `ps_axitrace_refund` WHERE `id_order` = (\\d+) ORDER BY `id`$/', $sql, $m) === 1) {
            return array_values(array_filter($this->refunds, static fn (array $r): bool => (int) $r['id_order'] === (int) $m[1]));
        }

        if (preg_match("/^SELECT \\* FROM `ps_axitrace_failed_event` WHERE `status` = '(\\w+)' AND `next_attempt_at` <= '([^']+)' ORDER BY `next_attempt_at`, `id` LIMIT (\\d+)$/", $sql, $m) === 1) {
            $rows = array_filter($this->failedEvents, static fn (array $r): bool => $r['status'] === $m[1] && $r['next_attempt_at'] <= $m[2]);
            uasort($rows, static fn (array $a, array $b): int => [$a['next_attempt_at'], (int) $a['id']] <=> [$b['next_attempt_at'], (int) $b['id']]);

            return array_slice(array_values($rows), 0, (int) $m[3]);
        }
        if (preg_match("/^SELECT MIN\\(`next_attempt_at`\\) AS `due` FROM `ps_axitrace_failed_event` WHERE `status` = '(\\w+)'$/", $sql, $m) === 1) {
            $dues = array_column(array_filter($this->failedEvents, static fn (array $r): bool => $r['status'] === $m[1]), 'next_attempt_at');

            return [['due' => $dues === [] ? null : min($dues)]];
        }
        if (preg_match("/^SELECT `id` FROM `ps_axitrace_failed_event` WHERE `event_id` = '([^']*)' AND `endpoint` = '(\\w+)' AND `status` = '(\\w+)' LIMIT 1$/", $sql, $m) === 1) {
            foreach ($this->failedEvents as $row) {
                if ($row['event_id'] === $m[1] && $row['endpoint'] === $m[2] && $row['status'] === $m[3]) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        throw new \LogicException('Query not supported by the fake: ' . $sql);
    }

    private function updateFailedEvents(string $sql): int
    {
        if (preg_match('/^DELETE FROM `ps_axitrace_failed_event` WHERE `id` = (\d+)$/', $sql, $m) === 1) {
            $found = isset($this->failedEvents[(int) $m[1]]);
            unset($this->failedEvents[(int) $m[1]]);

            return $found ? 1 : 0;
        }
        if (preg_match("/^DELETE FROM `ps_axitrace_failed_event` WHERE `status` = '(\\w+)' AND `next_attempt_at` < '([^']+)'$/", $sql, $m) === 1) {
            $before = count($this->failedEvents);
            $this->failedEvents = array_filter($this->failedEvents, static fn (array $r): bool => !($r['status'] === $m[1] && $r['next_attempt_at'] < $m[2]));

            return $before - count($this->failedEvents);
        }
        if (preg_match("/^UPDATE `ps_axitrace_failed_event` SET `next_attempt_at` = '([^']+)', `status` = '(\\w+)' WHERE `status` IN \\('(\\w+)', '(\\w+)'\\)$/", $sql, $m) === 1) {
            $changed = 0;
            foreach ($this->failedEvents as $id => $row) {
                if (in_array($row['status'], [$m[3], $m[4]], true) && ($row['next_attempt_at'] !== $m[1] || $row['status'] !== $m[2])) {
                    $this->failedEvents[$id]['next_attempt_at'] = $m[1];
                    $this->failedEvents[$id]['status'] = $m[2];
                    ++$changed;
                }
            }

            return $changed;
        }
        if (preg_match('/^DELETE FROM `ps_axitrace_failed_event` WHERE (.+)$/', $sql, $m) === 1 && str_contains($m[1], '`event_id`')) {
            $before = count($this->failedEvents);
            $matches = $this->orderCondition($m[1]);
            $this->failedEvents = array_filter($this->failedEvents, static fn (array $r): bool => !$matches($r));

            return $before - count($this->failedEvents);
        }
        if (preg_match('/^UPDATE `ps_axitrace_failed_event` SET (.*) WHERE `id` = (\d+)$/s', $sql, $m) === 1) {
            $id = (int) $m[2];
            if (!isset($this->failedEvents[$id])) {
                return 0;
            }
            foreach ($this->assignments($m[1]) as $column => $value) {
                $this->failedEvents[$id][$column] = $value;
            }

            return 1;
        }

        throw new \LogicException('Statement not supported by the fake: ' . $sql);
    }

    private function updateRefunds(string $sql): int
    {
        if (preg_match('/^INSERT IGNORE INTO `ps_axitrace_refund` \(([^)]*)\) VALUES \((.*)\)$/s', $sql, $m) === 1) {
            $row = $this->row($m[1], $m[2]);
            foreach ($this->refunds as $existing) {
                if ($existing['refund_id'] === $row['refund_id']) {
                    return 0;
                }
            }
            $this->refunds[$this->nextRefundId] = ['id' => (string) $this->nextRefundId] + $row + ['sent_at' => null];
            ++$this->nextRefundId;

            return 1;
        }
        if (preg_match("/^UPDATE `ps_axitrace_refund` SET `sent_at` = '([^']+)' WHERE `refund_id` = '((?:[^'\\\\]|\\\\.)*)'$/", $sql, $m) === 1) {
            $changed = 0;
            foreach ($this->refunds as $id => $row) {
                if ($row['refund_id'] === stripslashes($m[2])) {
                    $this->refunds[$id]['sent_at'] = $m[1];
                    ++$changed;
                }
            }

            return $changed;
        }
        if (preg_match("/^DELETE FROM `ps_axitrace_refund` WHERE `refund_id` = '((?:[^'\\\\]|\\\\.)*)'$/", $sql, $m) === 1) {
            $before = count($this->refunds);
            $this->refunds = array_filter($this->refunds, static fn (array $r): bool => $r['refund_id'] !== stripslashes($m[1]));

            return $before - count($this->refunds);
        }

        throw new \LogicException('Statement not supported by the fake: ' . $sql);
    }

    /**
     * The WHERE of FailedEventQueue::forOrder() / deleteForOrder().
     *
     * @return \Closure(array<string, mixed>): bool
     */
    private function orderCondition(string $where): \Closure
    {
        if (preg_match("/^(?:\\(`event_id` = '((?:[^'\\\\]|\\\\.)*)' OR )?`event_id` LIKE '((?:[^'\\\\]|\\\\.)*)%'\\)?$/", $where, $m) !== 1) {
            throw new \LogicException('Condition not supported by the fake: ' . $where);
        }
        $eventId = stripslashes($m[1]);
        $prefix = stripcslashes(stripslashes($m[2]));

        return static fn (array $row): bool => ($eventId !== '' && $row['event_id'] === $eventId) || str_starts_with((string) $row['event_id'], $prefix);
    }

    private function updateConfiguration(string $sql): int
    {
        if ($this->configuration === null
            || preg_match("/^UPDATE `ps_configuration` SET `value` = '(\\d+)' WHERE `name` = '(\\w+)' AND `id_shop` IS NULL AND `id_shop_group` IS NULL(?: AND \\(`value` IS NULL OR CAST\\(`value` AS UNSIGNED\\) < (-?\\d+)\\))?$/", $sql, $m) !== 1) {
            throw new \LogicException('Statement not supported by the fake: ' . $sql);
        }

        $key = '0:0:' . $m[2];
        if (!array_key_exists($key, $this->configuration->values)) {
            return 0;
        }
        $current = $this->configuration->values[$key];
        if (isset($m[3]) && (int) $current >= (int) $m[3]) {
            return 0;
        }
        if ($current === $m[1]) {
            return 0; // MySQL reports changed rows only.
        }
        $this->configuration->values[$key] = $m[1];

        return 1;
    }

    /**
     * @return array<string, string|null>
     */
    private function assignments(string $set): array
    {
        preg_match_all("/`(\\w+)` = (?:'((?:[^'\\\\]|\\\\.)*)'|(NULL)|(-?\\d+))/", $set, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        $values = [];
        foreach ($matches as $match) {
            $values[(string) $match[1]] = match (true) {
                ($match[3] ?? null) === 'NULL' => null,
                ($match[4] ?? null) !== null => (string) $match[4],
                default => stripslashes((string) $match[2]),
            };
        }

        return $values;
    }

    public function escape(string $value): string
    {
        return PsqlEscape::text($value);
    }

    public function escapeRaw(string $value): string
    {
        return PsqlEscape::raw($value);
    }

    public function escapeLike(string $value): string
    {
        return PsqlEscape::like($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $columns, string $values): array
    {
        $names = array_map(static fn (string $c): string => trim($c, " `"), explode(',', $columns));
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'|(NULL)|(-?\\d+)/s", $values, $tokens, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        $parsed = [];
        foreach ($tokens as $token) {
            if (($token[2] ?? null) === 'NULL') {
                $parsed[] = null;
            } elseif (($token[3] ?? null) !== null) {
                $parsed[] = $token[3];
            } else {
                $parsed[] = stripslashes((string) ($token[1] ?? ''));
            }
        }
        if (count($names) !== count($parsed)) {
            throw new \LogicException(sprintf('Column/value count mismatch: %s / %s', $columns, $values));
        }

        return array_combine($names, $parsed);
    }
}
