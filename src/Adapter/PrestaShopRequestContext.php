<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class PrestaShopRequestContext implements RequestContextInterface
{
    public function cookies(): array
    {
        $cookies = [];
        foreach ($_COOKIE as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }

    public function query(): array
    {
        $query = [];
        foreach ($_GET as $name => $value) {
            if (is_string($name)) {
                $query[$name] = $value;
            }
        }

        return $query;
    }

    public function clientIp(): string
    {
        return (string) \Tools::getRemoteAddr();
    }

    public function userAgent(): string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return is_string($agent) ? $agent : '';
    }

    public function url(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (!is_string($uri) || $uri === '') {
            return '';
        }
        $path = (string) strtok($uri, '?');

        return \Tools::getCurrentUrlProtocolPrefix() . \Tools::getHttpHost(false) . $path;
    }
}
