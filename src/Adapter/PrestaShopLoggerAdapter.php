<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class PrestaShopLoggerAdapter implements LoggerInterface
{
    private const SEVERITY_ERROR = 3;

    public function error(string $message): void
    {
        // allowDuplicate=false: a repeating failure (read on every page) is logged once, not per request.
        \PrestaShopLogger::addLog('AxiTrace: ' . $message, self::SEVERITY_ERROR, null, 'Axitrace', null, false);
    }
}
