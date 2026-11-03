<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class SecretKeyResult
{
    private function __construct(
        public readonly SecretKeyState $state,
        public readonly ?string $key,
    ) {
    }

    public static function absent(): self
    {
        return new self(SecretKeyState::Absent, null);
    }

    public static function present(string $key): self
    {
        return new self(SecretKeyState::Present, $key);
    }

    public static function undecryptable(): self
    {
        return new self(SecretKeyState::Undecryptable, null);
    }
}
