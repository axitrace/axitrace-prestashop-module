<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\LoggerInterface;

final class SpyLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $errors = [];

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
