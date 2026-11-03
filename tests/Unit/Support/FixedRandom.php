<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\SecureRandomInterface;

final class FixedRandom implements SecureRandomInterface
{
    public function hex(int $bytes): string
    {
        return str_repeat('ab', $bytes);
    }
}
