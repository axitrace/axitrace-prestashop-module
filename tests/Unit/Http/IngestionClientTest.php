<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Http;

use Axitrace\PrestaShop\Adapter\HttpResponse;
use Axitrace\PrestaShop\Http\DeliveryOutcome;
use Axitrace\PrestaShop\Http\IngestionClient;
use Axitrace\PrestaShop\Http\SecretKeyRejectedException;
use Axitrace\PrestaShop\Tests\Unit\Support\FakeHttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IngestionClientTest extends TestCase
{
    private const SK = 'sk_live_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return [
            'event' => 'transaction.charge',
            'eventSalt' => '5f1b0a8e-0000-5000-8000-000000000000',
            'orderId' => '30',
            'data' => ['products' => [
                ['productId' => '1', 'unitCost' => ['amount' => 5.49, 'currency' => 'EUR']],
                ['productId' => '6'],
            ]],
        ];
    }

    public function testPostsJsonToThePluginPixelEndpointWithShortTimeouts(): void
    {
        $transport = new FakeHttpTransport(new HttpResponse(202, ['content-type' => 'application/json'], '{"success":true,"eventId":"x"}'));
        $result = (new IngestionClient($transport))->sendPixel('http://velitrack-ingestion-api:8080/', self::payload(), '');

        self::assertSame(DeliveryOutcome::Sent, $result->outcome);
        self::assertSame(202, $result->httpStatus);
        self::assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        self::assertSame('http://velitrack-ingestion-api:8080/v1/plugin/pixel', $request['url']);
        self::assertSame(1, $request['connect']);
        self::assertSame(2, $request['total']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertArrayNotHasKey('Authorization', $request['headers']);
        self::assertSame(self::payload(), $request['payload']);
    }

    public function testSecretKeyGoesAsBasicUsername(): void
    {
        $transport = new FakeHttpTransport(new HttpResponse(202));
        (new IngestionClient($transport))->sendPixel('https://stat.axitrace.com', self::payload(), self::SK);

        self::assertSame('Basic ' . base64_encode(self::SK . ':'), $transport->requests[0]['headers']['Authorization']);
    }

    public function testCostKeyHeaderIsReported(): void
    {
        $transport = new FakeHttpTransport(new HttpResponse(202, ['x-axitrace-cost-key' => 'unverified']));
        $result = (new IngestionClient($transport))->sendPixel('https://stat.axitrace.com', self::payload(), self::SK);

        self::assertSame('unverified', $result->costKeyHeader);
    }

    public function testTimeoutIsARetry(): void
    {
        $transport = new FakeHttpTransport(FakeHttpTransport::timeout());
        $result = (new IngestionClient($transport))->sendPixel('https://stat.axitrace.com', self::payload(), '');

        self::assertSame(DeliveryOutcome::Retry, $result->outcome);
        self::assertNull($result->httpStatus);
        self::assertStringContainsString('transport error: cURL error 28', $result->error);
    }

    public function testUnauthorizedWithAKeyThrows(): void
    {
        $transport = new FakeHttpTransport(new HttpResponse(401, [], 'Invalid secret key for this workspace'));

        $this->expectException(SecretKeyRejectedException::class);
        (new IngestionClient($transport))->sendPixel('https://stat.axitrace.com', self::payload(), self::SK);
    }

    public function testUnauthorizedWithoutAKeyIsAPublicKeyRejection(): void
    {
        $transport = new FakeHttpTransport(new HttpResponse(401, [], "Invalid workspace_public_key\n"));
        $result = (new IngestionClient($transport))->sendPixel('https://stat.axitrace.com', self::payload(), '');

        self::assertSame(DeliveryOutcome::PublicKeyRejected, $result->outcome);
        self::assertSame('HTTP 401: Invalid workspace_public_key', $result->error);
    }

    /**
     * @return iterable<string, array{0: int, 1: DeliveryOutcome}>
     */
    public static function statuses(): iterable
    {
        yield '200' => [200, DeliveryOutcome::Sent];
        yield '202' => [202, DeliveryOutcome::Sent];
        yield '400' => [400, DeliveryOutcome::Rejected];
        yield '413' => [413, DeliveryOutcome::Rejected];
        yield '415' => [415, DeliveryOutcome::Rejected];
        yield '408' => [408, DeliveryOutcome::Retry];
        yield '429' => [429, DeliveryOutcome::Retry];
        yield '500' => [500, DeliveryOutcome::Retry];
        yield '502' => [502, DeliveryOutcome::Retry];
        yield '503' => [503, DeliveryOutcome::Retry];
        yield '403 from a proxy' => [403, DeliveryOutcome::Retry];
        yield '404 from a proxy' => [404, DeliveryOutcome::Retry];
    }

    #[DataProvider('statuses')]
    public function testStatusMapping(int $status, DeliveryOutcome $expected): void
    {
        $transport = new FakeHttpTransport(new HttpResponse($status, [], 'eventSalt required'));

        self::assertSame($expected, (new IngestionClient($transport))->sendPixel('https://stat.axitrace.com', self::payload(), '')->outcome);
    }

    public function testWithoutUnitCostsKeepsEverythingElse(): void
    {
        $stripped = IngestionClient::withoutUnitCosts(self::payload());

        self::assertSame([['productId' => '1'], ['productId' => '6']], $stripped['data']['products']);
        self::assertSame('30', $stripped['orderId']);
        self::assertSame(['event' => 'x'], IngestionClient::withoutUnitCosts(['event' => 'x']));
    }

    public function testErrorDetailIsBounded(): void
    {
        $transport = new FakeHttpTransport(new HttpResponse(400, [], str_repeat('x', 5000)));
        $result = (new IngestionClient($transport))->sendPixel('https://stat.axitrace.com', self::payload(), '');

        self::assertLessThan(140, strlen($result->error));
    }
}
