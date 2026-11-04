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
            $notices->errors($endpoints, SecretKeyResult::absent(), [['at' => '', 'code' => 'public_key_rejected', 'message' => '']]),
        );
        self::assertSame(
            ['Secret key rejected: AxiTrace refused the secret key, so purchases are sent without product costs and refunds wait in the delivery log. Enter the secret key of the same workspace as the public key, then click Retry now.'],
            $notices->errors($endpoints, SecretKeyResult::present('sk_live_x'), [['at' => '', 'code' => 'secret_key_rejected', 'message' => '']]),
        );
        self::assertSame([], $notices->errors($endpoints, SecretKeyResult::absent(), [['at' => '', 'code' => 'delivery_failed', 'message' => '']]));
    }

    public function testEveryRecordedKeyFailureGetsItsBannerNotOnlyTheNewest(): void
    {
        $errors = (new ConfigPageNotices(new EchoTranslator()))->errors(
            new ResolvedEndpoints('https://stat.axitrace.com', 'https://stat.axitrace.com'),
            SecretKeyResult::present('sk_live_x'),
            [
                ['at' => '2026-11-04T10:00:00+00:00', 'code' => 'delivery_failed', 'message' => ''],
                ['at' => '2026-11-04T09:00:00+00:00', 'code' => 'secret_key_rejected', 'message' => ''],
            ],
        );

        self::assertCount(1, $errors);
        self::assertStringStartsWith('Secret key rejected', $errors[0]);
    }
}
