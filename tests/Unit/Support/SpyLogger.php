<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\LoggerInterface;

final class SpyLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $infos = [];

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function info(string $message): void
    {
        $this->infos[] = $message;
    }
}
