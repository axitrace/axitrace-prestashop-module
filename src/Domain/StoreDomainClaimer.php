<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Domain;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;
use Axitrace\PrestaShop\Adapter\EnvironmentInterface;
use Axitrace\PrestaShop\Adapter\HttpTransportException;
use Axitrace\PrestaShop\Adapter\HttpTransportInterface;
use Axitrace\PrestaShop\Adapter\StoreHostsReaderInterface;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\SecretKeyVault;

/**
 * Asks AxiTrace to verify the storefront hosts of every shop, so their browser events are accepted
 * without the merchant adding the domains by hand (AxiTrace accepts browser events only from
 * verified domains of the workspace; purchases sent from the server arrive either way, so a
 * missing domain looked like nothing was broken).
 *
 * `POST https://axitrace.com/api/public/store-domains/claim` with the public key, the platform and
 * the shop hosts; with the secret key as HTTP Basic when the shop has one. Shops are grouped by
 * their keys (one claim per public key and secret key, at most 10 hosts each), so every proof the
 * shops answer ({@see DomainProofResponder}) is signed with the key the claim was made with.
 * AxiTrace then fetches the proof on each host. Its answer (per host: verified, pending, failed or
 * rejected, with a reason) is stored per shop in AXITRACE_DOMAIN_STATUS for the configuration page.
 *
 * Runs when the settings are saved, from "Verify store domain", at install and once a day
 * ({@see StoreDomainClaimTrigger}). Never throws: a failure is stored, logged and shown.
 */
final class StoreDomainClaimer
{
    public const DEFAULT_ADMIN_BASE_URL = 'https://axitrace.com';
    public const CLAIM_PATH = '/api/public/store-domains/claim';
    /** Developer override of {@see DEFAULT_ADMIN_BASE_URL}, read only with `_PS_MODE_DEV_ === true`. */
    public const ENV_ADMIN_OVERRIDE = 'AXITRACE_DEV_ADMIN_BASE_URL';
    public const MAX_HOSTS_PER_CLAIM = 10;
    public const CONNECT_TIMEOUT_SECONDS = 5;
    public const TOTAL_TIMEOUT_SECONDS = 20;
    public const INTERVAL_SECONDS = 86400;
    public const FAILURE_CODE = 'store_domain_claim';

    public function __construct(
        private readonly StoreHostsReaderInterface $shops,
        private readonly ModuleConfig $config,
        private readonly SecretKeyVault $vault,
        private readonly ConfigurationStoreInterface $store,
        private readonly HttpTransportInterface $transport,
        private readonly EnvironmentInterface $environment,
        private readonly FailureRecorder $failures,
        private readonly ClockInterface $clock,
        private readonly string $moduleVersion,
    ) {
    }

    /**
     * Claims the hosts of every shop now and stores the outcome per shop.
     *
     * @return array<int, StoreDomainStatus> keyed by shop id
     */
    public function claimAll(): array
    {
        try {
            $statuses = $this->claimShops($this->shops->shops());
            $this->store->setGlobal(ModuleConfig::DOMAIN_CLAIM_DUE_AT, (string) ($this->clock->now()->getTimestamp() + self::INTERVAL_SECONDS));
            $this->failures->clearGlobal(self::FAILURE_CODE);

            return $statuses;
        } catch (\Throwable $e) {
            $this->failures->recordGlobal(self::FAILURE_CODE, sprintf('The store domain could not be verified (%s: %s); it is tried again within a day.', $e::class, $e->getMessage()));

            return [];
        }
    }

    /**
     * The stored outcome of every shop (null for a shop that never claimed).
     *
     * @return list<array{shop: ShopHosts, status: StoreDomainStatus|null}>
     */
    public function lastStatuses(): array
    {
        $rows = [];
        foreach ($this->shops->shops() as $shop) {
            $rows[] = ['shop' => $shop, 'status' => $this->read($shop)];
        }

        return $rows;
    }

    /** The claim URL: axitrace.com, never the tracking domain. */
    public function claimUrl(): string
    {
        $base = self::DEFAULT_ADMIN_BASE_URL;
        if ($this->environment->isDevMode()) {
            $override = rtrim((string) $this->environment->env(self::ENV_ADMIN_OVERRIDE), '/');
            if (preg_match('#^https?://[^\s/?\#]+$#i', $override) === 1) {
                $base = $override;
            }
        }

        return $base . self::CLAIM_PATH;
    }

    /**
     * @param list<ShopHosts> $shops
     *
     * @return array<int, StoreDomainStatus>
     */
    private function claimShops(array $shops): array
    {
        $now = $this->clock->now()->getTimestamp();
        $statuses = [];

        /** @var array<string, array{public_key: string, secret_key: string, shops: list<ShopHosts>}> $groups */
        $groups = [];
        foreach ($shops as $shop) {
            $scope = $shop->scope();
            $publicKey = $this->config->publicKey($scope);
            if ($publicKey === '' || $shop->hosts === []) {
                $statuses[$shop->idShop] = $this->write($shop, new StoreDomainStatus(
                    $now,
                    [],
                    $publicKey === '' ? StoreDomainStatus::ERROR_NO_PUBLIC_KEY : StoreDomainStatus::ERROR_NO_STORE_HOST,
                ));
                continue;
            }
            $secretKey = (string) $this->vault->read($scope)->key;
            $groups[$publicKey . '|' . $secretKey] ??= ['public_key' => $publicKey, 'secret_key' => $secretKey, 'shops' => []];
            $groups[$publicKey . '|' . $secretKey]['shops'][] = $shop;
        }

        foreach ($groups as $group) {
            $hosts = [];
            foreach ($group['shops'] as $shop) {
                array_push($hosts, ...$shop->hosts);
            }

            /** @var array<string, array{host: string, status: string, reason: string|null, browser_events_rejected: bool}> $answered */
            $answered = [];
            /** @var array<string, string> $errors host => error code */
            $errors = [];
            foreach (array_chunk(array_values(array_unique($hosts)), self::MAX_HOSTS_PER_CLAIM) as $chunk) {
                $result = $this->claim($group['public_key'], $group['secret_key'], $chunk);
                foreach ($chunk as $host) {
                    if ($result['error'] !== null) {
                        $errors[$host] = $result['error'];
                    }
                }
                foreach ($result['hosts'] as $entry) {
                    $answered[$entry['host']] = $entry;
                }
            }

            foreach ($group['shops'] as $shop) {
                $previous = $this->read($shop);
                $entries = [];
                $error = null;
                foreach ($shop->hosts as $host) {
                    $error ??= $errors[$host] ?? null;
                    $entry = $answered[$host] ?? $previous?->host($host);
                    if ($entry !== null) {
                        $entries[] = $entry;
                    }
                }
                $statuses[$shop->idShop] = $this->write($shop, new StoreDomainStatus($now, $entries, $error));
            }
        }

        return $statuses;
    }

    /**
     * One claim request.
     *
     * @param list<string> $hosts
     *
     * @return array{hosts: list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>, error: string|null}
     */
    private function claim(string $publicKey, string $secretKey, array $hosts): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'AxiTrace-PrestaShop/' . $this->moduleVersion,
        ];
        if ($secretKey !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($secretKey . ':');
        }

        $body = json_encode(
            ['public_key' => $publicKey, 'platform' => DomainProofResponder::PLATFORM, 'hosts' => $hosts],
            \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );

        try {
            $response = $this->transport->post($this->claimUrl(), $headers, $body, self::CONNECT_TIMEOUT_SECONDS, self::TOTAL_TIMEOUT_SECONDS);
        } catch (HttpTransportException $e) {
            $this->failures->recordGlobal(self::FAILURE_CODE . '_unreachable', sprintf('Store domain verification: AxiTrace could not be reached (%s); it is tried again within a day.', mb_substr($e->getMessage(), 0, 200)));

            return ['hosts' => [], 'error' => StoreDomainStatus::ERROR_UNREACHABLE];
        }
        $this->failures->clearGlobal(self::FAILURE_CODE . '_unreachable');

        $json = json_decode($response->body, true);
        if ($response->status !== 200 || !is_array($json) || ($json['success'] ?? false) !== true || !is_array($json['hosts'] ?? null)) {
            $error = is_array($json) && is_string($json['error'] ?? null) && preg_match('/^[a-z0-9_]{1,64}$/', $json['error']) === 1
                ? $json['error']
                : 'http_' . $response->status;

            return ['hosts' => [], 'error' => $error];
        }

        return ['hosts' => StoreDomainStatus::hostsOf($json['hosts']), 'error' => null];
    }

    private function read(ShopHosts $shop): ?StoreDomainStatus
    {
        return StoreDomainStatus::fromJson($this->store->get(ModuleConfig::DOMAIN_STATUS, $shop->idShopGroup, $shop->idShop));
    }

    private function write(ShopHosts $shop, StoreDomainStatus $status): StoreDomainStatus
    {
        $this->store->set(ModuleConfig::DOMAIN_STATUS, $status->toJson(), $shop->idShopGroup, $shop->idShop);

        return $status;
    }
}
