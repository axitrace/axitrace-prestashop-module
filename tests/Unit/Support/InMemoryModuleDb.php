<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;

/**
 * Executes the module's own statements on `axitrace_cart_snapshot` and `axitrace_order` against
 * in-memory tables, so hook tests can assert on rows instead of SQL strings. Escaping is
 * addslashes, undone when the values are parsed back.
 */
final class InMemoryModuleDb implements DbGatewayInterface
{
    /** @var array<int, array<string, mixed>> keyed by id_cart */
    public array $cartSnapshots = [];

    /** @var array<int, array<string, mixed>> keyed by id_order */
    public array $orders = [];

    /** @var list<string> */
    public array $executed = [];

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

        if (preg_match("/^DELETE FROM `ps_axitrace_cart_snapshot` WHERE `updated_at` < '([^']+)'$/", $sql, $m) === 1) {
            $this->cartSnapshots = array_filter($this->cartSnapshots, static fn (array $row): bool => $row['updated_at'] >= $m[1]);

            return;
        }

        throw new \LogicException('Statement not supported by the fake: ' . $sql);
    }

    public function select(string $sql): array
    {
        if (preg_match('/FROM `ps_axitrace_cart_snapshot` WHERE `id_cart` = (\d+)/', $sql, $m) === 1) {
            return isset($this->cartSnapshots[(int) $m[1]]) ? [$this->cartSnapshots[(int) $m[1]]] : [];
        }
        if (preg_match('/FROM `ps_axitrace_order` WHERE `id_order` = (\d+)/', $sql, $m) === 1) {
            return isset($this->orders[(int) $m[1]]) ? [$this->orders[(int) $m[1]]] : [];
        }

        throw new \LogicException('Query not supported by the fake: ' . $sql);
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function escapeLike(string $value): string
    {
        return addcslashes(addslashes($value), '%_');
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
