<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Http;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class DeliveryResult
{
    /**
     * @param int|null    $httpStatus    null when no HTTP response was received
     * @param string      $error         short, PII-free description of a failure ('' on success)
     * @param string|null $costKeyHeader value of the x-axitrace-cost-key response header, if any
     */
    public function __construct(
        public readonly DeliveryOutcome $outcome,
        public readonly ?int $httpStatus = null,
        public readonly string $error = '',
        public readonly ?string $costKeyHeader = null,
    ) {
    }

    public function isSent(): bool
    {
        return $this->outcome === DeliveryOutcome::Sent;
    }
}
