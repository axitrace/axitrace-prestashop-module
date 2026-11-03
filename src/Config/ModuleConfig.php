<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;
use Axitrace\PrestaShop\Consent\ConsentMode;
use Axitrace\PrestaShop\Normalizer\ConversionValueBasis;

/**
 * Typed, validated read access to the module settings of one shop scope. Every value read from
 * Configuration is cast (PrestaShop returns strings or ints depending on the version) and
 * re-validated, so a corrupted row can only disable a feature, never put a wrong value on the wire.
 */
final class ModuleConfig
{
    public const PREFIX = 'AXITRACE_';

    public const ENABLED = 'AXITRACE_ENABLED';
    public const PUBLIC_KEY = 'AXITRACE_PUBLIC_KEY';
    public const SECRET_KEY = 'AXITRACE_SECRET_KEY';
    public const TRACKING_DOMAIN = 'AXITRACE_TRACKING_DOMAIN';
    public const VALUE_BASIS = 'AXITRACE_VALUE_BASIS';
    public const CONSENT_MODE = 'AXITRACE_CONSENT_MODE';
    public const CONSENT_COOKIE = 'AXITRACE_CONSENT_COOKIE';
    public const COD_MODULES = 'AXITRACE_COD_MODULES';
    public const EXCLUDED_GROUPS = 'AXITRACE_EXCLUDED_GROUPS';
    public const DEBUG = 'AXITRACE_DEBUG';
    public const CRON_TOKEN = 'AXITRACE_CRON_TOKEN';
    public const RETRY_DUE_AT = 'AXITRACE_RETRY_DUE_AT';
    public const RETRY_LOCK = 'AXITRACE_RETRY_LOCK';
    public const LAST_FAILURE = 'AXITRACE_LAST_FAILURE';
    public const LAST_SUCCESS = 'AXITRACE_LAST_SUCCESS';
    public const COST_KEY_STATE = 'AXITRACE_COST_KEY_STATE';

    /** Every key the module owns; uninstall also sweeps any other AXITRACE_* name. */
    public const ALL_KEYS = [
        self::ENABLED,
        self::PUBLIC_KEY,
        self::SECRET_KEY,
        self::TRACKING_DOMAIN,
        self::VALUE_BASIS,
        self::CONSENT_MODE,
        self::CONSENT_COOKIE,
        self::COD_MODULES,
        self::EXCLUDED_GROUPS,
        self::DEBUG,
        self::CRON_TOKEN,
        self::RETRY_DUE_AT,
        self::RETRY_LOCK,
        self::LAST_FAILURE,
        self::LAST_SUCCESS,
        self::COST_KEY_STATE,
    ];

    public const DEFAULT_TRACKING_DOMAIN = 'stat.axitrace.com';
    public const DEFAULT_COD_MODULES = 'ps_cashondelivery';

    /** The only public key format AxiTrace issues. */
    public const PUBLIC_KEY_PATTERN = '/^pk_live_[a-f0-9]{32}$/';

    /** The only secret key format AxiTrace issues. */
    public const SECRET_KEY_PATTERN = '/^sk_live_[a-f0-9]{64}$/';

    /** DNS host name: labels of letters, digits and hyphens, at least one dot, no port, no path. */
    public const HOST_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/';

    /** Cookie-name-safe subset; the name is echoed into the storefront JSON. */
    public const CONSENT_COOKIE_PATTERN = '/^[A-Za-z0-9_\-.]{1,128}$/';

    /** PrestaShop module technical name. */
    public const MODULE_NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';

    /**
     * Values written at install. Enable tracking stays off until the merchant pastes a key.
     *
     * @return array<string, string>
     */
    public static function installDefaults(): array
    {
        return [
            self::ENABLED => '0',
            self::PUBLIC_KEY => '',
            self::TRACKING_DOMAIN => self::DEFAULT_TRACKING_DOMAIN,
            self::VALUE_BASIS => ConversionValueBasis::GrossTotal->value,
            self::CONSENT_MODE => ConsentMode::Off->value,
            self::CONSENT_COOKIE => '',
            self::COD_MODULES => self::DEFAULT_COD_MODULES,
            self::EXCLUDED_GROUPS => '',
            self::DEBUG => '0',
            self::RETRY_LOCK => '0',
        ];
    }

    public function __construct(private readonly ConfigurationStoreInterface $store)
    {
    }

    /** Tracking runs only with the switch on AND a valid public key. */
    public function isEnabled(ShopScope $scope): bool
    {
        return $this->flag(self::ENABLED, $scope) && $this->publicKey($scope) !== '';
    }

    /** The public key, or '' when unset or malformed. */
    public function publicKey(ShopScope $scope): string
    {
        $key = trim($this->raw(self::PUBLIC_KEY, $scope));

        return preg_match(self::PUBLIC_KEY_PATTERN, $key) === 1 ? $key : '';
    }

    /**
     * The tracking domain as stored (lowercase, trimmed); the default when empty. Safety checks
     * (scheme, IP literal, private ranges) belong to {@see EndpointResolver}.
     */
    public function trackingDomain(ShopScope $scope): string
    {
        $domain = strtolower(trim($this->raw(self::TRACKING_DOMAIN, $scope)));

        return $domain === '' ? self::DEFAULT_TRACKING_DOMAIN : $domain;
    }

    public function valueBasis(ShopScope $scope): ConversionValueBasis
    {
        return ConversionValueBasis::fromConfigValue($this->store->get(self::VALUE_BASIS, $scope->idShopGroup, $scope->idShop));
    }

    public function consentMode(ShopScope $scope): ConsentMode
    {
        return ConsentMode::fromConfigValue($this->store->get(self::CONSENT_MODE, $scope->idShopGroup, $scope->idShop));
    }

    /** The consent cookie name, or '' when unset or not a safe cookie name. */
    public function consentCookie(ShopScope $scope): string
    {
        $name = trim($this->raw(self::CONSENT_COOKIE, $scope));

        return preg_match(self::CONSENT_COOKIE_PATTERN, $name) === 1 ? $name : '';
    }

    /**
     * Technical names of the cash on delivery payment modules. The default (ps_cashondelivery) is
     * written at install only: falling back to the default on read would make the list impossible
     * to empty. An emptied value saved for one shop stays empty for that shop (PrestaShop reads the
     * stored NULL back as '' and does not fall back to the group or all shops; checked on 8.1.7
     * and 9.2.0).
     *
     * @return list<string>
     */
    public function codModules(ShopScope $scope): array
    {
        return self::parseModuleList($this->raw(self::COD_MODULES, $scope));
    }

    /**
     * Ids of the customer groups excluded from tracking. Non-numeric entries are dropped, so a
     * corrupted value can only exclude less, never everyone.
     *
     * @return list<int>
     */
    public function excludedGroupIds(ShopScope $scope): array
    {
        return self::parseIdList($this->raw(self::EXCLUDED_GROUPS, $scope));
    }

    public function isDebug(ShopScope $scope): bool
    {
        return $this->flag(self::DEBUG, $scope);
    }

    public function cronToken(): string
    {
        return trim((string) $this->store->get(self::CRON_TOKEN));
    }

    /**
     * @return list<string>
     */
    public static function parseModuleList(string $raw): array
    {
        $names = [];
        foreach (explode(',', $raw) as $part) {
            $name = trim($part);
            if ($name !== '' && preg_match(self::MODULE_NAME_PATTERN, $name) === 1) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<int>
     */
    public static function parseIdList(string $raw): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    private function flag(string $key, ShopScope $scope): bool
    {
        return (int) $this->raw($key, $scope) === 1;
    }

    private function raw(string $key, ShopScope $scope): string
    {
        return (string) $this->store->get($key, $scope->idShopGroup, $scope->idShop);
    }
}
