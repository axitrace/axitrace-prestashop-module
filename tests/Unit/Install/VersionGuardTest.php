<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Install;

use Axitrace\PrestaShop\Install\VersionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function versions(): iterable
    {
        yield '1.7.8.11 refused' => ['1.7.8.11', 80100, false];
        yield '8.0.0 refused' => ['8.0.0', 80100, false];
        yield '8.0.5 refused' => ['8.0.5', 80200, false];
        yield '8.1.0 accepted' => ['8.1.0', 80100, true];
        yield '8.1.7 accepted' => ['8.1.7', 80134, true];
        yield '8.2.8 accepted' => ['8.2.8', 80134, true];
        yield '9.2.0 accepted' => ['9.2.0', 80512, true];
        yield 'PHP 8.0 refused' => ['8.1.7', 80030, false];
        yield 'PHP 7.4 refused' => ['8.1.0', 70433, false];
    }

    #[DataProvider('versions')]
    public function testSupportedPlatforms(string $prestaShop, int $php, bool $expected): void
    {
        self::assertSame($expected, VersionGuard::isSupported($prestaShop, $php));
    }
}
