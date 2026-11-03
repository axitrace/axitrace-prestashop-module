<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's only access to the shop database. Every statement uses {@see prefix()} for table
 * names; values are cast or escaped by the caller.
 */
interface DbGatewayInterface
{
    public function prefix(): string;

    /** MySQL engine configured for the shop (`_MYSQL_ENGINE_`). */
    public function engine(): string;

    /** Runs a statement that returns no rows; throws when the database reports a failure. */
    public function execute(string $sql): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function select(string $sql): array;

    public function escape(string $value): string;

    /** Escapes a value for use inside a LIKE pattern (also escapes `%` and `_`). */
    public function escapeLike(string $value): string;
}
