<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The parts of the current HTTP request the attribution snapshot reads.
 */
interface RequestContextInterface
{
    /**
     * @return array<string, string>
     */
    public function cookies(): array;

    /**
     * @return array<string, mixed>
     */
    public function query(): array;

    /** The client IP as PrestaShop resolves it (proxy headers per the shop settings), or ''. */
    public function clientIp(): string;

    public function userAgent(): string;

    /** Scheme, host and path of the request, without the query string, or ''. */
    public function url(): string;
}
