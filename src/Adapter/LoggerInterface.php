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
}
