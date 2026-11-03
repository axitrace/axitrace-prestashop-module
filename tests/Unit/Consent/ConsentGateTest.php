<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Consent;

use Axitrace\PrestaShop\Consent\ConsentGate;
use Axitrace\PrestaShop\Consent\ConsentMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsentGateTest extends TestCase
{
    /**
     * The deny-list must stay byte-identical to the web SDK (CONSENT_DENY_VALUES); the fixture is
     * the SDK's literal, diffed against web-sdk/velitrack-sdk.js by scripts/check-sdk-deny-values.sh.
     */
    public function testDenyValuesEqualTheSdkFixture(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/sdk_deny_values.txt'), true, 2, \JSON_THROW_ON_ERROR);

        self::assertSame($fixture, ConsentGate::DENY_VALUES);
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function signals(): iterable
    {
        yield 'absent' => [null, false];
        yield 'empty' => ['', false];
        yield 'zero' => ['0', false];
        yield 'false' => ['false', false];
        yield 'no upper case' => ['NO', false];
        yield 'deny padded' => [' deny ', false];
        yield 'denied' => ['denied', false];
        yield 'opt-out' => ['opt-out', false];
        yield 'one' => ['1', true];
        yield 'yes' => ['yes', true];
        yield 'json blob' => ['{"marketing":true}', true];
    }

    #[DataProvider('signals')]
    public function testGrantSignal(?string $raw, bool $granted): void
    {
        self::assertSame($granted, (new ConsentGate())->isGrantSignal($raw));
    }

    public function testDecisionFromCookie(): void
    {
        $gate = new ConsentGate();

        self::assertSame(ConsentGate::DECISION_GRANTED, $gate->decisionFromCookie('c', '1'));
        self::assertSame(ConsentGate::DECISION_DENIED, $gate->decisionFromCookie('c', 'opt-out'));
        self::assertNull($gate->decisionFromCookie('c', null), 'no cookie proves nothing');
        self::assertNull($gate->decisionFromCookie('', '1'), 'no cookie name configured');
    }

    public function testServerTrackingIsFailClosedOnlyInModeAll(): void
    {
        $gate = new ConsentGate();

        self::assertTrue($gate->allowsServerTracking(ConsentMode::Off, null));
        self::assertTrue($gate->allowsServerTracking(ConsentMode::Browser, ConsentGate::DECISION_DENIED));
        self::assertTrue($gate->allowsServerTracking(ConsentMode::All, ConsentGate::DECISION_GRANTED));
        self::assertFalse($gate->allowsServerTracking(ConsentMode::All, ConsentGate::DECISION_DENIED));
        self::assertFalse($gate->allowsServerTracking(ConsentMode::All, null));
    }
}
