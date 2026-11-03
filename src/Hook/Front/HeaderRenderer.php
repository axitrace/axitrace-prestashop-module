<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Hook\Front;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Config\EndpointResolver;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Exclusion\CustomerGroupExclusion;

/**
 * The storefront configuration rendered on every page (`displayHeader`) as the JSON element
 * `axitrace-prestashop-config`. It holds shop-level settings only - never anything about the
 * visitor - because page-cache modules serve the same HTML to every visitor. Whether the current
 * customer may be tracked is asked from the uncached status controller (statusUrl), and the
 * consent decision is made in the visitor's browser.
 *
 * The browser URLs always come from the SDK base (the developer override included), never from
 * the server API base, which may be a host only the shop server can reach.
 */
final class HeaderRenderer
{
    public const ELEMENT_ID = 'axitrace-prestashop-config';
    public const SDK_PATH = '/axitrack.js';

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly EndpointResolver $endpointResolver,
        private readonly CustomerGroupExclusion $exclusion,
    ) {
    }

    /**
     * @param string $pageType  the front controller's php_self
     * @param string $statusUrl absolute URL of the module's status controller
     *
     * @return array<string, mixed>|null null when tracking is off for the shop: nothing is rendered
     */
    public function config(ShopScope $scope, string $pageType, string $moduleVersion, string $statusUrl): ?array
    {
        if (!$this->config->isEnabled($scope)) {
            return null;
        }

        $endpoints = $this->endpointResolver->resolve($this->config->trackingDomain($scope));

        return [
            'publicKey' => $this->config->publicKey($scope),
            'sdkUrl' => $endpoints->sdkBaseUrl . self::SDK_PATH,
            'apiUrl' => $endpoints->sdkBaseUrl,
            'consentMode' => $this->config->consentMode($scope)->value,
            'consentCookie' => $this->config->consentCookie($scope),
            'pageType' => self::pageType($pageType),
            'moduleVersion' => $moduleVersion,
            // Asked before the SDK loads only when a group is excluded: otherwise every visitor
            // is tracked and the extra request would be wasted.
            'statusUrl' => $this->exclusion->isConfigured($scope) ? $statusUrl : '',
            'debug' => $this->config->isDebug($scope),
        ];
    }

    /** The JSON element content, or '' when tracking is off. */
    public function render(ShopScope $scope, string $pageType, string $moduleVersion, string $statusUrl): string
    {
        $config = $this->config($scope, $pageType, $moduleVersion, $statusUrl);

        return $config === null ? '' : StorefrontJson::encode($config);
    }

    private static function pageType(string $phpSelf): string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $phpSelf) === 1 ? $phpSelf : 'page';
    }
}
