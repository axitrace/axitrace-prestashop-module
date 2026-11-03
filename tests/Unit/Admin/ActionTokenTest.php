<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Admin;

use Axitrace\PrestaShop\Admin\ActionToken;
use PHPUnit\Framework\TestCase;

final class ActionTokenTest extends TestCase
{
    public function testTokenIsBoundToActionEmployeeAndSecret(): void
    {
        $tokens = new ActionToken('cookie-key-1');
        $token = $tokens->forEmployee('submitAxitraceRetryNow', 1);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, $tokens->forEmployee('submitAxitraceRetryNow', 1), 'stable for the employee');
        self::assertTrue($tokens->isValid('submitAxitraceRetryNow', 1, $token));
        self::assertFalse($tokens->isValid('submitAxitraceRetryNow', 2, $token), 'another employee');
        self::assertFalse($tokens->isValid('otherAction', 1, $token), 'another action');
        self::assertFalse((new ActionToken('cookie-key-2'))->isValid('submitAxitraceRetryNow', 1, $token), 'another shop');
        self::assertFalse($tokens->isValid('submitAxitraceRetryNow', 1, ''));
        self::assertFalse($tokens->isValid('submitAxitraceRetryNow', 1, 'bad'));
    }

    public function testNoSecretOrNoEmployeeNeverValidates(): void
    {
        self::assertSame('', (new ActionToken(''))->forEmployee('submitAxitraceRetryNow', 1));
        self::assertFalse((new ActionToken(''))->isValid('submitAxitraceRetryNow', 1, ''));
        self::assertSame('', (new ActionToken('k'))->forEmployee('submitAxitraceRetryNow', 0));
        self::assertFalse((new ActionToken('k'))->isValid('submitAxitraceRetryNow', 0, ''));
    }
}
