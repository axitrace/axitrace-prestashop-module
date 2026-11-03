<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's only outbound HTTP call (server-to-server, to the AxiTrace ingestion API).
 */
interface HttpTransportInterface
{
    /**
     * POSTs a body and returns the status and the response headers (names lowercased).
     *
     * @param array<string, string> $headers
     *
     * @throws HttpTransportException when no HTTP response was received (DNS, connect, TLS, timeout)
     */
    public function post(string $url, array $headers, string $body, int $connectTimeoutSeconds, int $totalTimeoutSeconds): HttpResponse;
}
