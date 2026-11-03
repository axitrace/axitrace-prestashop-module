<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Refund;

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
use Axitrace\PrestaShop\Purchase\PurchaseSender;

/**
 * Delivers one refund body for a shop scope (`POST /v1/refund`), for the refund hook (first
 * attempt) and the retry runner (later attempts): reads that shop's endpoint and secret key at the
 * moment of sending (the queue never stores the key), names the shop's workspace
 * (`workspace_public_key`, so AxiTrace refuses a secret key of ANOTHER workspace instead of
 * booking the refund there) and records the outcome for the back office.
 *
 * Refunds are profit data: the endpoint accepts the secret key only, so without a key nothing is
 * sent, and a rejected key is never retried (it cannot succeed with the same key).
 */
final class RefundSender
{
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
     * @param array<string, mixed> $body
     *
     * @throws SecretKeyRejectedException when AxiTrace rejected the key (recorded for the banner)
     */
    public function send(array $body, ShopScope $scope): DeliveryResult
    {
        $secretKey = $this->vault->read($scope)->key ?? '';
        if ($secretKey === '') {
            return new DeliveryResult(DeliveryOutcome::Retry, null, 'no secret key configured: refunds are sent with the secret key only');
        }

        $publicKey = $this->config->publicKey($scope);
        if ($publicKey !== '' && !is_string($body['workspace_public_key'] ?? null)) {
            $body['workspace_public_key'] = $publicKey;
        }

        $refundId = is_scalar($body['refundId'] ?? null) ? (string) $body['refundId'] : '?';
        $apiBaseUrl = $this->endpoints->resolve($this->config->trackingDomain($scope))->apiBaseUrl;

        try {
            $result = $this->client->sendRefund($apiBaseUrl, $body, $secretKey);
        } catch (SecretKeyRejectedException $e) {
            // Never the key itself in a message, not even a prefix.
            $this->failures->record(
                PurchaseSender::FAILURE_SECRET_KEY_REJECTED,
                sprintf('AxiTrace rejected the secret key (HTTP 401) for the refund %s. Check that it is the secret key of the same workspace as the public key; refunds are not reported until it is fixed.', $refundId),
                $scope,
            );

            throw $e;
        }

        switch ($result->outcome) {
            case DeliveryOutcome::Sent:
                $this->health->recordSuccess($scope, true, $result->costKeyHeader);
                $this->failures->clear(PurchaseSender::FAILURE_DELIVERY, $scope);
                $this->failures->clear(PurchaseSender::FAILURE_SECRET_KEY_REJECTED, $scope);
                break;
            case DeliveryOutcome::Retry:
                $this->failures->record(
                    PurchaseSender::FAILURE_DELIVERY,
                    sprintf('The refund %s could not be delivered (%s); it is retried later.', $refundId, $result->error),
                    $scope,
                );
                break;
            case DeliveryOutcome::Rejected:
            case DeliveryOutcome::PublicKeyRejected:
                $this->failures->record(
                    PurchaseSender::FAILURE_PAYLOAD_REJECTED,
                    sprintf('AxiTrace refused the refund %s (%s); it is not retried.', $refundId, $result->error),
                    $scope,
                );
                break;
        }

        return $result;
    }
}
