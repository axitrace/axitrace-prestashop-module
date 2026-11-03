<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** No HTTP response: DNS, connect, TLS or timeout failure. Always worth a retry. */
final class HttpTransportException extends \RuntimeException
{
}
