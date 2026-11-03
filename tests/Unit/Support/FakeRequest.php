<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\RequestContextInterface;

final class FakeRequest implements RequestContextInterface
{
    /**
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $query
     */
    public function __construct(
        public array $cookies = [],
        public array $query = [],
        public string $clientIp = '',
        public string $userAgent = '',
        public string $url = '',
    ) {
    }

    public function cookies(): array
    {
        return $this->cookies;
    }

    public function query(): array
    {
        return $this->query;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function url(): string
    {
        return $this->url;
    }
}
