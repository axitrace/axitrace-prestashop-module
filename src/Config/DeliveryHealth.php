<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;

/**
 * What the back-office health panel shows about deliveries of one shop scope: the time of the last
 * accepted delivery (AXITRACE_LAST_SUCCESS) and whether AxiTrace accepted the secret key for costs
 * (AXITRACE_COST_KEY_STATE, from the x-axitrace-cost-key response header).
 */
final class DeliveryHealth
{
    /** The request carried the secret key and AxiTrace kept the costs. */
    public const COST_KEY_ACCEPTED = 'accepted';

    /** The request carried a key AxiTrace does not recognise: accepted, costs dropped. */
    public const COST_KEY_UNVERIFIED = 'unverified';

    /** No secret key configured: purchases are sent without costs. */
    public const COST_KEY_NONE = 'none';

    public function __construct(
        private readonly ConfigurationStoreInterface $store,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param bool        $withSecretKey the accepted request carried the secret key
     * @param string|null $costKeyHeader the x-axitrace-cost-key response header
     */
    public function recordSuccess(ShopScope $scope, bool $withSecretKey, ?string $costKeyHeader): void
    {
        $this->store->set(ModuleConfig::LAST_SUCCESS, $this->clock->now()->format(\DATE_ATOM), $scope->idShopGroup, $scope->idShop);

        $state = match (true) {
            !$withSecretKey => self::COST_KEY_NONE,
            strtolower(trim((string) $costKeyHeader)) === self::COST_KEY_UNVERIFIED => self::COST_KEY_UNVERIFIED,
            default => self::COST_KEY_ACCEPTED,
        };
        if ($this->costKeyState($scope) !== $state) {
            $this->store->set(ModuleConfig::COST_KEY_STATE, $state, $scope->idShopGroup, $scope->idShop);
        }
    }

    public function lastSuccess(ShopScope $scope): string
    {
        return (string) $this->store->get(ModuleConfig::LAST_SUCCESS, $scope->idShopGroup, $scope->idShop);
    }

    public function costKeyState(ShopScope $scope): string
    {
        return (string) $this->store->get(ModuleConfig::COST_KEY_STATE, $scope->idShopGroup, $scope->idShop);
    }
}
