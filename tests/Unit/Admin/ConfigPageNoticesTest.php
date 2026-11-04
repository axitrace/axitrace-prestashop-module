<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Admin;

use Axitrace\PrestaShop\Admin\ConfigPageNotices;
use Axitrace\PrestaShop\Config\EndpointError;
use Axitrace\PrestaShop\Config\ResolvedEndpoints;
use Axitrace\PrestaShop\Config\SecretKeyResult;
use Axitrace\PrestaShop\Tests\Unit\Support\EchoTranslator;
use PHPUnit\Framework\TestCase;

final class ConfigPageNoticesTest extends TestCase
{
    public function testHealthySetupShowsNoBanner(): void
    {
        $notices = new ConfigPageNotices(new EchoTranslator());

        self::assertSame([], $notices->errors(
            new ResolvedEndpoints('https://stat.axitrace.com', 'https://stat.axitrace.com'),
            SecretKeyResult::present('sk_live_x'),
        ));
    }

    public function testOverrideRefusedDomainAndUndecryptableKeyAreAllShown(): void
    {
        $errors = (new ConfigPageNotices(new EchoTranslator()))->errors(
            new ResolvedEndpoints('http://velitrack-ingestion-api:8080', 'http://localhost:8080', EndpointError::PrivateAddress, 'pixel.shop.example', true),
            SecretKeyResult::undecryptable(),
        );

        self::assertSame([
            'Developer endpoint override active: http://velitrack-ingestion-api:8080',
            'Developer endpoint override active: http://localhost:8080',
            'The tracking domain pixel.shop.example was refused: the host name points to a private or local network address. The module uses stat.axitrace.com until you correct it.',
            'The saved secret key can no longer be decrypted, most likely because the shop encryption key changed. Re-enter the secret key; until then purchases are sent without costs.',
        ], $errors);
    }

    public function testSameOverrideUrlForApiAndSdkIsShownOnce(): void
    {
        $errors = (new ConfigPageNotices(new EchoTranslator()))->errors(
            new ResolvedEndpoints('http://localhost:8080', 'http://localhost:8080', null, '', true),
            SecretKeyResult::absent(),
        );

        self::assertSame(['Developer endpoint override active: http://localhost:8080'], $errors);
    }

    public function testRejectedKeysFromTheLastFailureAreShown(): void
    {
        $notices = new ConfigPageNotices(new EchoTranslator());
        $endpoints = new ResolvedEndpoints('https://stat.axitrace.com', 'https://stat.axitrace.com');

        self::assertSame(
            ['Public key rejected: AxiTrace does not recognise the public key, so purchases are not tracked. Copy the public key of your workspace again.'],
            $notices->errors($endpoints, SecretKeyResult::absent(), [['code' => 'public_key_rejected', 'message' => '', 'first_at' => '', 'last_at' => '', 'count' => 1]]),
        );
        self::assertSame(
            ['Secret key rejected: AxiTrace refused the secret key, so purchases are sent without product costs and refunds wait in the delivery log. Enter the secret key of the same workspace as the public key, then click Retry now.'],
            $notices->errors($endpoints, SecretKeyResult::present('sk_live_x'), [['code' => 'secret_key_rejected', 'message' => '', 'first_at' => '', 'last_at' => '', 'count' => 1]]),
        );
        self::assertSame([], $notices->errors($endpoints, SecretKeyResult::absent(), [['code' => 'delivery_failed', 'message' => '', 'first_at' => '', 'last_at' => '', 'count' => 1]]));
    }

    public function testEveryRecordedKeyFailureGetsItsBannerNotOnlyTheNewest(): void
    {
        $errors = (new ConfigPageNotices(new EchoTranslator()))->errors(
            new ResolvedEndpoints('https://stat.axitrace.com', 'https://stat.axitrace.com'),
            SecretKeyResult::present('sk_live_x'),
            [
                ['code' => 'delivery_failed', 'message' => '', 'first_at' => '2026-11-04T10:00:00+00:00', 'last_at' => '2026-11-04T10:00:00+00:00', 'count' => 1],
                ['code' => 'secret_key_rejected', 'message' => '', 'first_at' => '2026-11-04T09:00:00+00:00', 'last_at' => '2026-11-04T09:00:00+00:00', 'count' => 1],
            ],
        );

        self::assertCount(1, $errors);
        self::assertStringStartsWith('Secret key rejected', $errors[0]);
    }

    public function testParkedRefundsGetABannerFromTheQueueCount(): void
    {
        $notices = new ConfigPageNotices(new EchoTranslator());
        $endpoints = new ResolvedEndpoints('https://stat.axitrace.com', 'https://stat.axitrace.com');

        self::assertSame([], $notices->errors($endpoints, SecretKeyResult::present('sk_live_x'), [], 0));
        $errors = $notices->errors($endpoints, SecretKeyResult::present('sk_live_x'), [], 3);
        self::assertSame(['3 refunds wait for a valid secret key: AxiTrace rejected the saved one. Enter the secret key of the same workspace as the public key; they are sent as soon as you save it.'], $errors, 'shown without any recorded failure');
    }
}
