<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;
use Axitrace\PrestaShop\Adapter\LoggerInterface;

/**
 * Records the most recent failure of a shop scope in AXITRACE_LAST_FAILURE (JSON: at, code,
 * message) and in the PrestaShop log, so the back office can show it. A failure that repeats on
 * every request is written once: the stored row is only replaced when the code changes.
 */
final class FailureRecorder
{
    public function __construct(
        private readonly ConfigurationStoreInterface $store,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function record(string $code, string $message, ShopScope $scope): void
    {
        $this->logger->error($message);

        $last = $this->last($scope);
        if ($last !== null && $last['code'] === $code) {
            return;
        }

        $this->store->set(
            ModuleConfig::LAST_FAILURE,
            (string) json_encode(
                ['at' => $this->clock->now()->format(\DATE_ATOM), 'code' => $code, 'message' => $message],
                \JSON_UNESCAPED_SLASHES,
            ),
            $scope->idShopGroup,
            $scope->idShop,
        );
    }

    public function clear(string $code, ShopScope $scope): void
    {
        $last = $this->last($scope);
        if ($last !== null && $last['code'] === $code) {
            $this->store->set(ModuleConfig::LAST_FAILURE, '', $scope->idShopGroup, $scope->idShop);
        }
    }

    /**
     * @return array{at: string, code: string, message: string}|null
     */
    public function last(ShopScope $scope): ?array
    {
        $raw = (string) $this->store->get(ModuleConfig::LAST_FAILURE, $scope->idShopGroup, $scope->idShop);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['code']) || !is_string($decoded['code'])) {
            return null;
        }

        return [
            'at' => is_string($decoded['at'] ?? null) ? $decoded['at'] : '',
            'code' => $decoded['code'],
            'message' => is_string($decoded['message'] ?? null) ? $decoded['message'] : '',
        ];
    }
}
