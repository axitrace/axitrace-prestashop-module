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
}
