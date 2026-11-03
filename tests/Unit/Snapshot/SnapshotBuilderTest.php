<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Snapshot;

use Axitrace\PrestaShop\Consent\ConsentGate;
use Axitrace\PrestaShop\Normalizer\ClickIdReader;
use Axitrace\PrestaShop\Snapshot\SnapshotBuilder;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeRequest;
use PHPUnit\Framework\TestCase;

final class SnapshotBuilderTest extends TestCase
{
    public function testFullSnapshot(): void
    {
        $request = new FakeRequest(
            [
                'vt_vid' => '0b6d6c9e-1111-4222-8333-944445555666',
                'vt_sid' => '0b6d6c9e-aaaa-4bbb-8ccc-9ddddeeeefff',
                '_fbp' => 'fb.1.1700000000000.123456789',
                '_fbc' => 'fb.1.1700000000000.IwAR2abc',
                '_ga' => 'GA1.2.111.222',
                '_ga_ABC123' => 'GS1.1.1700000000.1.0.1700000000.0.0.0',
                '_ttp' => '01KCFX1BV9NB74R5ZE592YDTYN_.tt.1',
                'consent' => 'yes',
                'unrelated' => 'x',
            ],
            ['gclid' => 'Cj0KCQ'],
            '203.0.113.7',
            'Mozilla/5.0 (Macintosh)',
            'https://shop.example/order',
        );

        $snapshot = $this->builder()->build($request, 'consent', 1_793_000_000_000);

        self::assertSame([
            'clickIds' => ['gclid' => 'Cj0KCQ'],
            'clientIp' => '203.0.113.7',
            'consent' => 'granted',
            'fbc' => 'fb.1.1700000000000.IwAR2abc',
            'fbp' => 'fb.1.1700000000000.123456789',
            'ga' => 'GA1.2.111.222',
            'gaSession' => 'GS1.1.1700000000.1.0.1700000000.0.0.0',
            'landingUrl' => 'https://shop.example/order',
            'sessionId' => '0b6d6c9e-aaaa-4bbb-8ccc-9ddddeeeefff',
            'ttp' => '01KCFX1BV9NB74R5ZE592YDTYN_.tt.1',
            'userAgent' => 'Mozilla/5.0 (Macintosh)',
            'visitorId' => '0b6d6c9e-1111-4222-8333-944445555666',
        ], $snapshot);
    }

    public function testMalformedValuesAreLeftOut(): void
    {
        $request = new FakeRequest(['_fbp' => 'not-a-fbp', 'vt_vid' => 'x', '_ga' => 'GA1'], [], 'not-an-ip', '', 'javascript:alert(1)');

        self::assertSame([], $this->builder()->build($request, '', 0));
    }

    public function testConsentDecision(): void
    {
        $denied = $this->builder()->build(new FakeRequest(['c' => 'opt-out']), 'c', 0);
        self::assertSame('denied', $denied['consent']);

        $none = $this->builder()->build(new FakeRequest(['c' => '1']), '', 0);
        self::assertArrayNotHasKey('consent', $none, 'no consent cookie configured: nothing provable');
    }

    public function testHashIgnoresKeyOrder(): void
    {
        self::assertSame(
            SnapshotBuilder::hash(['b' => 1, 'a' => ['y' => 1, 'x' => 2]]),
            SnapshotBuilder::hash(['a' => ['x' => 2, 'y' => 1], 'b' => 1]),
        );
        self::assertNotSame(SnapshotBuilder::hash(['a' => 1]), SnapshotBuilder::hash(['a' => 2]));
    }

    private function builder(): SnapshotBuilder
    {
        return new SnapshotBuilder(new ClickIdReader(), new ConsentGate());
    }
}
