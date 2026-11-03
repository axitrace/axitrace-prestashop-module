<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
