<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Purchase;

use Axitrace\PrestaShop\Adapter\HttpResponse;
use Axitrace\PrestaShop\Config\DeliveryHealth;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Http\DeliveryOutcome;
use Axitrace\PrestaShop\Purchase\PurchaseSender;
use Axitrace\PrestaShop\Tests\Unit\Support\PurchasePipeline;
use PHPUnit\Framework\TestCase;

final class PurchaseSenderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function payloadWithCosts(): array
    {
        return [
            'event' => 'transaction.charge',
            'eventSalt' => '5f1b0a8e-0000-5000-8000-000000000000',
            'orderId' => '30',
            'data' => ['products' => [['productId' => '1', 'unitCost' => ['amount' => 5.49, 'currency' => 'EUR']]]],
        ];
    }

    public function testWithoutASecretKeyCostsNeverLeave(): void
    {
        $pipeline = new PurchasePipeline();
        $result = $pipeline->sender()->send(self::payloadWithCosts(), ShopScope::shop(1, 1));

        self::assertSame(DeliveryOutcome::Sent, $result->outcome);
        self::assertArrayNotHasKey('unitCost', $pipeline->transport->requests[0]['payload']['data']['products'][0]);
        self::assertArrayNotHasKey('Authorization', $pipeline->transport->requests[0]['headers']);
        self::assertSame(DeliveryHealth::COST_KEY_NONE, $pipeline->health()->costKeyState(ShopScope::shop(1, 1)));
    }

    public function testWithTheSecretKeyCostsAreSent(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        $pipeline->sender()->send(self::payloadWithCosts(), ShopScope::shop(1, 1));

        self::assertSame(['amount' => 5.49, 'currency' => 'EUR'], $pipeline->transport->requests[0]['payload']['data']['products'][0]['unitCost']);
        self::assertSame(DeliveryHealth::COST_KEY_ACCEPTED, $pipeline->health()->costKeyState(ShopScope::shop(1, 1)));
    }

    public function testRejectedKeyResendsOnceWithoutKeyAndWithoutCosts(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        $pipeline->transport->willAnswer(new HttpResponse(401), new HttpResponse(202));

        $result = $pipeline->sender()->send(self::payloadWithCosts(), ShopScope::shop(1, 1));

        self::assertSame(DeliveryOutcome::Sent, $result->outcome);
        self::assertCount(2, $pipeline->transport->requests);
        self::assertArrayHasKey('unitCost', $pipeline->transport->requests[0]['payload']['data']['products'][0]);
        self::assertArrayNotHasKey('unitCost', $pipeline->transport->requests[1]['payload']['data']['products'][0]);
        self::assertArrayNotHasKey('Authorization', $pipeline->transport->requests[1]['headers']);
        self::assertSame(PurchaseSender::FAILURE_SECRET_KEY_REJECTED, $pipeline->lastFailure()['code'] ?? null);
    }

    public function testRejectedKeyBannerClearsOnceTheKeyIsAcceptedAgain(): void
    {
        $pipeline = new PurchasePipeline();
        $scope = ShopScope::shop(1, 1);
        $pipeline->vault()->store(PurchasePipeline::SK, $scope);
        $pipeline->transport->willAnswer(new HttpResponse(401), new HttpResponse(202), new HttpResponse(202));

        $pipeline->sender()->send(self::payloadWithCosts(), $scope);
        self::assertNotNull($pipeline->lastFailure());
        $pipeline->sender()->send(self::payloadWithCosts(), $scope);

        self::assertNull($pipeline->lastFailure());
    }

    public function testUnverifiedKeyIsReportedForTheHealthPanel(): void
    {
        $pipeline = new PurchasePipeline();
        $pipeline->vault()->store(PurchasePipeline::SK, ShopScope::shop(1, 1));
        $pipeline->transport->willAnswer(new HttpResponse(202, ['x-axitrace-cost-key' => 'unverified']));

        $pipeline->sender()->send(self::payloadWithCosts(), ShopScope::shop(1, 1));

        self::assertSame(DeliveryHealth::COST_KEY_UNVERIFIED, $pipeline->health()->costKeyState(ShopScope::shop(1, 1)));
    }

    public function testFailuresAreRecordedPerOutcome(): void
    {
        foreach ([
            [new HttpResponse(401), PurchaseSender::FAILURE_PUBLIC_KEY_REJECTED],
            [new HttpResponse(413), PurchaseSender::FAILURE_PAYLOAD_REJECTED],
            [new HttpResponse(503), PurchaseSender::FAILURE_DELIVERY],
        ] as [$response, $code]) {
            $pipeline = new PurchasePipeline();
            $pipeline->transport->willAnswer($response);
            $pipeline->sender()->send(self::payloadWithCosts(), ShopScope::shop(1, 1));

            $failure = $pipeline->lastFailure();
            self::assertNotNull($failure);
            self::assertSame($code, $failure['code']);
            self::assertStringContainsString('order 30', $failure['message']);
        }
    }
}
