<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * cURL implementation of {@see HttpTransportInterface}. PrestaShop requires the curl extension.
 * Redirects are not followed (an ingestion endpoint never redirects; following one could carry
 * the Authorization header to another host).
 */
final class HttpTransport implements HttpTransportInterface
{
    /** A response body is only read for an error code; anything longer is cut. */
    private const MAX_BODY_BYTES = 8192;

    public function post(string $url, array $headers, string $body, int $connectTimeoutSeconds, int $totalTimeoutSeconds): HttpResponse
    {
        if ($url === '') {
            throw new HttpTransportException('No URL to post to.');
        }
        if (!function_exists('curl_init')) {
            throw new HttpTransportException('The PHP curl extension is not available.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new HttpTransportException('curl_init() failed.');
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $responseBody = '';

        curl_setopt_array($handle, [
            \CURLOPT_URL => $url,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            \CURLOPT_TIMEOUT => $totalTimeoutSeconds,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            \CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) < self::MAX_BODY_BYTES) {
                    $responseBody .= substr($chunk, 0, self::MAX_BODY_BYTES - strlen($responseBody));
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        // No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5; the handle is freed here.
        unset($handle);

        if ($ok === false || $status === 0) {
            throw new HttpTransportException(sprintf('cURL error %d: %s', $errno, $error));
        }

        return new HttpResponse($status, $responseHeaders, $responseBody);
    }
}
