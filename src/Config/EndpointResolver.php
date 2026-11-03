<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\EnvironmentInterface;
use Axitrace\PrestaShop\Adapter\HostResolverInterface;

/**
 * Turns the tracking domain setting into the ingestion API base URL (server-to-server calls) and
 * the SDK base URL (browser). Both use the same host over HTTPS.
 *
 * SSRF guard (port of the Shopware plugin's IngestionApiClient): a domain with a scheme, an IP
 * literal, a malformed host, a host that does not resolve or one resolving to a private, loopback
 * or reserved range is refused; the module falls back to stat.axitrace.com and reports the error
 * on the configuration page.
 *
 * Developer override: only when PrestaShop runs with `_PS_MODE_DEV_ === true` AND the environment
 * variable AXITRACE_DEV_API_BASE_URL and/or AXITRACE_DEV_SDK_BASE_URL is set, that URL is used
 * verbatim (http and private addresses allowed). It is never stored in Configuration and never
 * offered in the form, so a production shop cannot render or save it.
 */
final class EndpointResolver
{
    public const DEFAULT_BASE_URL = 'https://' . ModuleConfig::DEFAULT_TRACKING_DOMAIN;
    public const ENV_API_OVERRIDE = 'AXITRACE_DEV_API_BASE_URL';
    public const ENV_SDK_OVERRIDE = 'AXITRACE_DEV_SDK_BASE_URL';

    public function __construct(
        private readonly EnvironmentInterface $environment,
        private readonly HostResolverInterface $hostResolver,
    ) {
    }

    public function resolve(string $trackingDomain): ResolvedEndpoints
    {
        $resolved = $this->resolveDomain($trackingDomain);

        if (!$this->environment->isDevMode()) {
            return $resolved;
        }

        $apiOverride = $this->environment->env(self::ENV_API_OVERRIDE);
        $sdkOverride = $this->environment->env(self::ENV_SDK_OVERRIDE);
        if ($apiOverride === null && $sdkOverride === null) {
            return $resolved;
        }

        return new ResolvedEndpoints(
            $apiOverride !== null ? rtrim($apiOverride, '/') : $resolved->apiBaseUrl,
            $sdkOverride !== null ? rtrim($sdkOverride, '/') : $resolved->sdkBaseUrl,
            $resolved->error,
            $resolved->rejectedDomain,
            true,
        );
    }

    private function resolveDomain(string $trackingDomain): ResolvedEndpoints
    {
        $domain = strtolower(trim($trackingDomain));
        if ($domain === '' || $domain === ModuleConfig::DEFAULT_TRACKING_DOMAIN) {
            return new ResolvedEndpoints(self::DEFAULT_BASE_URL, self::DEFAULT_BASE_URL);
        }

        $error = $this->check($domain);
        if ($error !== null) {
            return new ResolvedEndpoints(self::DEFAULT_BASE_URL, self::DEFAULT_BASE_URL, $error, $domain);
        }

        $base = 'https://' . $domain;

        return new ResolvedEndpoints($base, $base);
    }

    private function check(string $domain): ?EndpointError
    {
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#', $domain, $scheme) === 1) {
            return $scheme[1] === 'http' ? EndpointError::InsecureScheme : EndpointError::InvalidHost;
        }

        if (self::isIpLiteral($domain)) {
            return EndpointError::IpLiteral;
        }

        if (preg_match(ModuleConfig::HOST_PATTERN, $domain) !== 1) {
            return EndpointError::InvalidHost;
        }

        $ips = $this->hostResolver->resolve($domain);
        if ($ips === []) {
            return EndpointError::Unresolvable;
        }

        foreach ($ips as $ip) {
            if (self::isPrivateOrReservedIp($ip)) {
                return EndpointError::PrivateAddress;
            }
        }

        return null;
    }

    public static function isIpLiteral(string $host): bool
    {
        $host = trim($host, '[]');

        return filter_var($host, \FILTER_VALIDATE_IP) !== false;
    }

    /** True for private, loopback, link-local and otherwise reserved addresses. */
    public static function isPrivateOrReservedIp(string $ip): bool
    {
        if ($ip === '::1') {
            return true;
        }
        $lower = strtolower($ip);
        if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') || str_starts_with($lower, 'fe80')) {
            return true;
        }

        return filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
