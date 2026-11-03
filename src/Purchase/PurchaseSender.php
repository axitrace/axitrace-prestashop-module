<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Purchase;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Config\DeliveryHealth;
use Axitrace\PrestaShop\Config\EndpointResolver;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\SecretKeyVault;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Http\DeliveryOutcome;
use Axitrace\PrestaShop\Http\DeliveryResult;
use Axitrace\PrestaShop\Http\IngestionClient;
use Axitrace\PrestaShop\Http\SecretKeyRejectedException;

/**
 * Delivers one purchase payload for a shop scope: resolves that shop's endpoint and secret key at
 * the moment of sending (the queue never stores the key), strips the costs when there is no key,
 * resends once without key and costs when AxiTrace rejects the key, and records the outcome for the
 * back office (last success, cost-key state, last failure and its banner).
 *
 * Used by the status hook (first attempt) and the retry runner (later attempts), so both follow
 * the same rules.
 */
final class PurchaseSender
{
    public const FAILURE_SECRET_KEY_REJECTED = 'secret_key_rejected';
    public const FAILURE_PUBLIC_KEY_REJECTED = 'public_key_rejected';
    public const FAILURE_PAYLOAD_REJECTED = 'payload_rejected';
    public const FAILURE_DELIVERY = 'delivery_failed';

    public function __construct(
        private readonly IngestionClient $client,
        private readonly ModuleConfig $config,
        private readonly SecretKeyVault $vault,
        private readonly EndpointResolver $endpoints,
        private readonly FailureRecorder $failures,
        private readonly DeliveryHealth $health,
    ) {
    }

    /**
     * @param array<string, mixed> $payload a purchase as built by the normalizer (may hold costs)
     */
    public function send(array $payload, ShopScope $scope): DeliveryResult
    {
        $secretKey = $this->vault->read($scope)->key ?? '';
        if ($secretKey === '') {
            $payload = IngestionClient::withoutUnitCosts($payload);
        }

        $apiBaseUrl = $this->endpoints->resolve($this->config->trackingDomain($scope))->apiBaseUrl;
        $orderId = is_scalar($payload['orderId'] ?? null) ? (string) $payload['orderId'] : '?';

        try {
            $result = $this->client->sendPixel($apiBaseUrl, $payload, $secretKey);
            $keyUsed = $secretKey !== '';
        } catch (SecretKeyRejectedException) {
            // Never the key itself in a message, not even a prefix.
            $this->failures->record(
                self::FAILURE_SECRET_KEY_REJECTED,
                sprintf('AxiTrace rejected the secret key (HTTP 401) for order %s. Check that it is the secret key of the same workspace as the public key; purchases are sent without product costs until it is fixed.', $orderId),
                $scope,
            );
            $result = $this->client->sendPixel($apiBaseUrl, IngestionClient::withoutUnitCosts($payload), '');
            $keyUsed = false;
        }

        $this->record($result, $scope, $orderId, $keyUsed);

        return $result;
    }

    private function record(DeliveryResult $result, ShopScope $scope, string $orderId, bool $keyUsed): void
    {
        switch ($result->outcome) {
            case DeliveryOutcome::Sent:
                $this->health->recordSuccess($scope, $keyUsed, $result->costKeyHeader);
                $this->failures->clear(self::FAILURE_PUBLIC_KEY_REJECTED, $scope);
                $this->failures->clear(self::FAILURE_DELIVERY, $scope);
                if ($keyUsed) {
                    $this->failures->clear(self::FAILURE_SECRET_KEY_REJECTED, $scope);
                }
                break;
            case DeliveryOutcome::PublicKeyRejected:
                $this->failures->record(
                    self::FAILURE_PUBLIC_KEY_REJECTED,
                    sprintf('AxiTrace rejected the public key (HTTP 401) for order %s. Copy the public key of your workspace again; purchases are not tracked until it is fixed.', $orderId),
                    $scope,
                );
                break;
            case DeliveryOutcome::Rejected:
                $this->failures->record(
                    self::FAILURE_PAYLOAD_REJECTED,
                    sprintf('AxiTrace refused the purchase of order %s (%s); it is not retried.', $orderId, $result->error),
                    $scope,
                );
                break;
            case DeliveryOutcome::Retry:
                $this->failures->record(
                    self::FAILURE_DELIVERY,
                    sprintf('The purchase of order %s could not be delivered (%s); it is retried later.', $orderId, $result->error),
                    $scope,
                );
                break;
        }
    }
}
