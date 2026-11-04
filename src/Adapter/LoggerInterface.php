<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface LoggerInterface
{
    /** A failure the merchant must see: written to the PrestaShop log with severity 3 (error). */
    public function error(string $message): void;

    /** Something wrong the module worked around (a fallback was used): severity 2 (warning). */
    public function warning(string $message): void;

    /** A decision worth tracing (an order deliberately not sent): severity 1 (informative). */
    public function info(string $message): void;
}
