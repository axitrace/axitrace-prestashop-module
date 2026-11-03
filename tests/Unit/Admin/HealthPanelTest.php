<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Admin;

use Axitrace\PrestaShop\Admin\HealthPanel;
use Axitrace\PrestaShop\Config\DeliveryHealth;
use Axitrace\PrestaShop\Config\EndpointError;
use Axitrace\PrestaShop\Config\ResolvedEndpoints;
use Axitrace\PrestaShop\Config\SecretKeyState;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Normalizer\PlatformInfo;
use Axitrace\PrestaShop\Tests\Unit\Support\EchoTranslator;
use Axitrace\PrestaShop\Tests\Unit\Support\PurchasePipeline;
use PHPUnit\Framework\TestCase;

final class HealthPanelTest extends TestCase
{
    private const ENDPOINTS = 'https://stat.axitrace.com';

    private static function panel(PurchasePipeline $pipeline, ?EchoTranslator $translator = null): HealthPanel
    {
        return new HealthPanel(
            $pipeline->health(),
            $pipeline->failures(),
            $pipeline->queue(),
            new PlatformInfo('0.1.0', '9.2.0', '8.5.12'),
            $translator ?? new EchoTranslator(),
        );
    }

    /**
     * @param list<array{label: string, value: string, state: string}> $rows
     *
     * @return array<string, array{value: string, state: string}>
     */
    private static function byLabel(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['label']] = ['value' => $row['value'], 'state' => $row['state']];
        }

        return $out;
    }

    public function testFreshInstall(): void
    {
        $rows = self::byLabel(self::panel(new PurchasePipeline())->rows(ShopScope::shop(1, 1), new ResolvedEndpoints(self::ENDPOINTS, self::ENDPOINTS), SecretKeyState::Absent));

        self::assertSame(['value' => self::ENDPOINTS, 'state' => 'ok'], $rows['Events are sent to']);
        self::assertSame(['value' => 'None yet', 'state' => 'warning'], $rows['Last successful delivery']);
        self::assertSame(['value' => 'None', 'state' => 'ok'], $rows['Last failure']);
        self::assertSame(['value' => 'Not sent: no secret key is saved.', 'state' => 'warning'], $rows['Product costs and refunds']);
        self::assertSame(['value' => '0 waiting for a retry, 0 failed', 'state' => 'ok'], $rows['Delivery queue']);
        self::assertSame(['value' => 'Module 0.1.0, PrestaShop 9.2.0, PHP 8.5.12', 'state' => 'ok'], $rows['Versions']);
    }

    public function testDeliveriesFailuresCostsAndQueue(): void
    {
        $pipeline = new PurchasePipeline();
        $scope = ShopScope::shop(1, 1);
        $pipeline->health()->recordSuccess($scope, true, null);
        $pipeline->failures()->record('delivery_failed', 'The purchase of order 30 could not be delivered (HTTP 503); it is retried later.', $scope);
        $now = $pipeline->clock->now();
        $pipeline->queue()->enqueue('e1', 'pixel', 1, ['orderId' => '30'], 'HTTP 503', $now);
        $pipeline->queue()->addDead('e2', 'refund', 1, ['refundId' => 'r'], 'HTTP 400', $now);

        $rows = self::byLabel(self::panel($pipeline)->rows($scope, new ResolvedEndpoints(self::ENDPOINTS, self::ENDPOINTS), SecretKeyState::Present));

        self::assertSame(['value' => '2026-11-03T22:00:00+00:00', 'state' => 'ok'], $rows['Last successful delivery']);
        self::assertSame('error', $rows['Last failure']['state']);
        self::assertStringContainsString('could not be delivered (HTTP 503)', $rows['Last failure']['value']);
        self::assertSame(['value' => 'Accepted by AxiTrace.', 'state' => 'ok'], $rows['Product costs and refunds']);
        self::assertSame(['value' => '1 waiting for a retry, 1 failed', 'state' => 'error'], $rows['Delivery queue']);
    }

    public function testCostKeyStates(): void
    {
        $scope = ShopScope::shop(1, 1);
        $endpoints = new ResolvedEndpoints(self::ENDPOINTS, self::ENDPOINTS);

        $pipeline = new PurchasePipeline();
        $pipeline->health()->recordSuccess($scope, true, DeliveryHealth::COST_KEY_UNVERIFIED);
        self::assertSame('error', self::byLabel(self::panel($pipeline)->rows($scope, $endpoints, SecretKeyState::Present))['Product costs and refunds']['state']);

        $pipeline = new PurchasePipeline();
        self::assertSame(
            ['value' => 'A secret key is saved; nothing was delivered with it yet.', 'state' => 'warning'],
            self::byLabel(self::panel($pipeline)->rows($scope, $endpoints, SecretKeyState::Present))['Product costs and refunds'],
        );
        self::assertSame(
            ['value' => 'Not sent: the secret key must be entered again.', 'state' => 'error'],
            self::byLabel(self::panel($pipeline)->rows($scope, $endpoints, SecretKeyState::Undecryptable))['Product costs and refunds'],
        );
    }

    public function testOverrideAndRefusedDomainAreWarnings(): void
    {
        $scope = ShopScope::shop(1, 1);
        $panel = self::panel(new PurchasePipeline());

        self::assertSame('warning', self::byLabel($panel->rows($scope, new ResolvedEndpoints('http://velitrack-ingestion-api:8080', self::ENDPOINTS, null, '', true), SecretKeyState::Absent))['Events are sent to']['state']);
        self::assertSame('warning', self::byLabel($panel->rows($scope, new ResolvedEndpoints(self::ENDPOINTS, self::ENDPOINTS, EndpointError::PrivateAddress, 'intranet.example'), SecretKeyState::Absent))['Events are sent to']['state']);
    }

    public function testEveryLabelGoesThroughTheAdminDomain(): void
    {
        $translator = new EchoTranslator();
        self::panel(new PurchasePipeline(), $translator)->rows(ShopScope::shop(1, 1), new ResolvedEndpoints(self::ENDPOINTS, self::ENDPOINTS), SecretKeyState::Absent);

        self::assertNotSame([], $translator->domains);
        self::assertSame(['Modules.Axitrace.Admin'], array_values(array_unique($translator->domains)));
    }
}
