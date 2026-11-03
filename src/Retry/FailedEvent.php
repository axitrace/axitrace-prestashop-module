<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Retry;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** One `axitrace_failed_event` row (DB values cast: 8.2 returns strings). */
final class FailedEvent
{
    public function __construct(
        public readonly int $id,
        public readonly string $eventId,
        public readonly string $endpoint,
        public readonly int $idShop,
        public readonly string $payload,
        public readonly int $attempts,
        public readonly string $status,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            is_scalar($row['event_id'] ?? null) ? (string) $row['event_id'] : '',
            is_scalar($row['endpoint'] ?? null) ? (string) $row['endpoint'] : '',
            is_numeric($row['id_shop'] ?? null) ? (int) $row['id_shop'] : 0,
            is_scalar($row['payload'] ?? null) ? (string) $row['payload'] : '',
            is_numeric($row['attempts'] ?? null) ? (int) $row['attempts'] : 0,
            is_scalar($row['status'] ?? null) ? (string) $row['status'] : '',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodedPayload(): ?array
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }
}
