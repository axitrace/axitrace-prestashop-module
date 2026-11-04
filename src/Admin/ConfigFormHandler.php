<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;
use Axitrace\PrestaShop\Adapter\TranslatorInterface;
use Axitrace\PrestaShop\Config\EndpointResolver;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\SecretKeyState;
use Axitrace\PrestaShop\Config\SecretKeyVault;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Consent\ConsentMode;
use Axitrace\PrestaShop\Normalizer\ConversionValueBasis;

/**
 * The configuration form: HelperForm definition, current values, validation and saving. Values are
 * saved into the shop context selected in the back office (multistore).
 *
 * Field labels match the PrestaShop install guide on axitrace.com; option names match the
 * AxiTrace Shopware plugin.
 */
final class ConfigFormHandler
{
    public const SUBMIT = 'submitAxitraceConfig';
    public const FIELD_SECRET_CLEAR = 'AXITRACE_SECRET_KEY_CLEAR';
    /** HelperForm checkbox inputs are named "<name>_<value id>". */
    public const FIELD_SECRET_CLEAR_INPUT = self::FIELD_SECRET_CLEAR . '_on';
    public const FIELD_EXCLUDED_GROUPS_INPUT = ModuleConfig::EXCLUDED_GROUPS . '[]';
    /**
     * Hidden field carrying {@see ActionToken} for {@see SUBMIT}: on 9.x a POST to the legacy
     * configuration URL with a wrong or missing back-office token is redirected (308, body kept)
     * to the Symfony route with a fresh valid token, so only this token proves the form was
     * rendered for the employee (checked on 9.2.0).
     */
    public const FIELD_ACTION_TOKEN = 'axitrace_config_token';

    /** Request fields read on submit. */
    public const FIELDS = [
        ModuleConfig::ENABLED,
        ModuleConfig::PUBLIC_KEY,
        ModuleConfig::SECRET_KEY,
        self::FIELD_SECRET_CLEAR_INPUT,
        ModuleConfig::TRACKING_DOMAIN,
        ModuleConfig::VALUE_BASIS,
        ModuleConfig::CONSENT_MODE,
        ModuleConfig::CONSENT_COOKIE,
        ModuleConfig::COD_MODULES,
        ModuleConfig::EXCLUDED_GROUPS,
        ModuleConfig::DEBUG,
    ];

    public function __construct(
        private readonly ConfigurationStoreInterface $store,
        private readonly ModuleConfig $config,
        private readonly SecretKeyVault $vault,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Validates submitted values; returns translated error messages (empty when valid).
     *
     * @param array<string, mixed> $input  values keyed by {@see FIELDS}
     * @param list<int>            $groupIds customer group ids that may be excluded
     *
     * @return list<string>
     */
    public function validate(array $input, array $groupIds): array
    {
        $t = $this->translator;
        $errors = [];

        $publicKey = self::text($input, ModuleConfig::PUBLIC_KEY);
        if ($publicKey === '' && self::flag($input, ModuleConfig::ENABLED)) {
            $errors[] = $t->trans('Enter the public key to enable tracking.', [], 'Modules.Axitrace.Admin');
        } elseif ($publicKey !== '' && preg_match(ModuleConfig::PUBLIC_KEY_PATTERN, $publicKey) !== 1) {
            $errors[] = $t->trans('The public key must be pk_live_ followed by 32 lowercase hexadecimal characters. Copy it from Tracking & domains in your AxiTrace workspace.', [], 'Modules.Axitrace.Admin');
        }

        $secretKey = self::text($input, ModuleConfig::SECRET_KEY);
        if ($secretKey !== '' && !self::flag($input, self::FIELD_SECRET_CLEAR_INPUT)
            && preg_match(ModuleConfig::SECRET_KEY_PATTERN, $secretKey) !== 1) {
            $errors[] = $t->trans('The secret key must be sk_live_ followed by 64 lowercase hexadecimal characters.', [], 'Modules.Axitrace.Admin');
        }

        $domainError = $this->validateTrackingDomain(self::text($input, ModuleConfig::TRACKING_DOMAIN));
        if ($domainError !== null) {
            $errors[] = $domainError;
        }

        if (ConversionValueBasis::tryFrom(self::text($input, ModuleConfig::VALUE_BASIS)) === null) {
            $errors[] = $t->trans('Choose a conversion value from the list.', [], 'Modules.Axitrace.Admin');
        }

        if (ConsentMode::tryFrom(self::text($input, ModuleConfig::CONSENT_MODE)) === null) {
            $errors[] = $t->trans('Choose a cookie consent mode from the list.', [], 'Modules.Axitrace.Admin');
        }

        $cookie = self::text($input, ModuleConfig::CONSENT_COOKIE);
        if ($cookie !== '' && preg_match(ModuleConfig::CONSENT_COOKIE_PATTERN, $cookie) !== 1) {
            $errors[] = $t->trans('The consent cookie name may contain only letters, digits, dots, hyphens and underscores (up to 128 characters).', [], 'Modules.Axitrace.Admin');
        }

        foreach (explode(',', self::text($input, ModuleConfig::COD_MODULES)) as $name) {
            $name = trim($name);
            if ($name !== '' && preg_match(ModuleConfig::MODULE_NAME_PATTERN, $name) !== 1) {
                $errors[] = $t->trans('Cash on delivery modules must be technical module names separated by commas, for example ps_cashondelivery.', [], 'Modules.Axitrace.Admin');
                break;
            }
        }

        foreach (self::list($input, ModuleConfig::EXCLUDED_GROUPS) as $value) {
            if (!ctype_digit($value) || !in_array((int) $value, $groupIds, true)) {
                $errors[] = $t->trans('Select customer groups from the list.', [], 'Modules.Axitrace.Admin');
                break;
            }
        }

        return $errors;
    }

    /**
     * Saves validated values into the current shop context.
     *
     * @param array<string, mixed> $input
     */
    public function save(array $input, ShopScope $scope): void
    {
        $this->set(ModuleConfig::ENABLED, self::flag($input, ModuleConfig::ENABLED) ? '1' : '0', $scope);
        $this->set(ModuleConfig::PUBLIC_KEY, self::text($input, ModuleConfig::PUBLIC_KEY), $scope);

        $domain = strtolower(self::text($input, ModuleConfig::TRACKING_DOMAIN));
        $this->set(ModuleConfig::TRACKING_DOMAIN, $domain === '' ? ModuleConfig::DEFAULT_TRACKING_DOMAIN : $domain, $scope);

        $this->set(ModuleConfig::VALUE_BASIS, self::text($input, ModuleConfig::VALUE_BASIS), $scope);
        $this->set(ModuleConfig::CONSENT_MODE, self::text($input, ModuleConfig::CONSENT_MODE), $scope);
        $this->set(ModuleConfig::CONSENT_COOKIE, self::text($input, ModuleConfig::CONSENT_COOKIE), $scope);
        $this->set(
            ModuleConfig::COD_MODULES,
            implode(',', ModuleConfig::parseModuleList(self::text($input, ModuleConfig::COD_MODULES))),
            $scope,
        );
        $this->set(
            ModuleConfig::EXCLUDED_GROUPS,
            implode(',', ModuleConfig::parseIdList(implode(',', self::list($input, ModuleConfig::EXCLUDED_GROUPS)))),
            $scope,
        );
        $this->set(ModuleConfig::DEBUG, self::flag($input, ModuleConfig::DEBUG) ? '1' : '0', $scope);

        if (self::flag($input, self::FIELD_SECRET_CLEAR_INPUT)) {
            $this->vault->clear($scope);
        } elseif (self::text($input, ModuleConfig::SECRET_KEY) !== '') {
            $this->vault->store(self::text($input, ModuleConfig::SECRET_KEY), $scope);
        }
    }

    /**
     * Values for HelperForm::$fields_value. The secret key is never rendered back.
     *
     * @return array<string, mixed>
     */
    public function currentValues(ShopScope $scope): array
    {
        return [
            ModuleConfig::ENABLED => (int) $this->store->get(ModuleConfig::ENABLED, $scope->idShopGroup, $scope->idShop) === 1 ? 1 : 0,
            ModuleConfig::PUBLIC_KEY => (string) $this->store->get(ModuleConfig::PUBLIC_KEY, $scope->idShopGroup, $scope->idShop),
            ModuleConfig::SECRET_KEY => '',
            self::FIELD_SECRET_CLEAR_INPUT => false,
            ModuleConfig::TRACKING_DOMAIN => $this->config->trackingDomain($scope),
            ModuleConfig::VALUE_BASIS => $this->config->valueBasis($scope)->value,
            ModuleConfig::CONSENT_MODE => $this->config->consentMode($scope)->value,
            ModuleConfig::CONSENT_COOKIE => (string) $this->store->get(ModuleConfig::CONSENT_COOKIE, $scope->idShopGroup, $scope->idShop),
            ModuleConfig::COD_MODULES => implode(',', $this->config->codModules($scope)),
            self::FIELD_EXCLUDED_GROUPS_INPUT => $this->config->excludedGroupIds($scope),
            ModuleConfig::DEBUG => $this->config->isDebug($scope) ? 1 : 0,
        ];
    }

    /**
     * HelperForm definition.
     *
     * @param list<array{id: int, name: string}> $groups
     *
     * @return list<array<string, mixed>>
     */
    public function formDefinition(array $groups, SecretKeyState $secretKeyState): array
    {
        $t = $this->translator;
        $yesNo = [
            ['id' => 'on', 'value' => 1, 'label' => $t->trans('Yes', [], 'Modules.Axitrace.Admin')],
            ['id' => 'off', 'value' => 0, 'label' => $t->trans('No', [], 'Modules.Axitrace.Admin')],
        ];

        $secretState = match ($secretKeyState) {
            SecretKeyState::Present => $t->trans('A secret key is saved. Leave the field empty to keep it.', [], 'Modules.Axitrace.Admin'),
            SecretKeyState::Undecryptable => $t->trans('The saved secret key can no longer be decrypted. Enter it again.', [], 'Modules.Axitrace.Admin'),
            SecretKeyState::Absent => $t->trans('No secret key is saved.', [], 'Modules.Axitrace.Admin'),
        };

        $groupOptions = array_map(
            static fn (array $group): array => [
                'id' => $group['id'],
                'name' => htmlspecialchars($group['name'], \ENT_QUOTES, 'UTF-8'),
            ],
            $groups,
        );

        return [[
            'form' => [
                'legend' => [
                    'title' => $t->trans('Settings', [], 'Modules.Axitrace.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $t->trans('Enable tracking', [], 'Modules.Axitrace.Admin'),
                        'name' => ModuleConfig::ENABLED,
                        'is_bool' => true,
                        'values' => $yesNo,
                        'desc' => $t->trans('Master switch. Off, or no public key: the module renders nothing on the storefront and sends nothing.', [], 'Modules.Axitrace.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $t->trans('Public key', [], 'Modules.Axitrace.Admin'),
                        'name' => ModuleConfig::PUBLIC_KEY,
                        'class' => 'fixed-width-xxl',
                        'placeholder' => 'pk_live_',
                        'desc' => $t->trans('Your workspace public key (pk_live_ followed by 32 characters), from Tracking & domains in your AxiTrace workspace.', [], 'Modules.Axitrace.Admin'),
                    ],
                    [
                        'type' => 'password',
                        'label' => $t->trans('Secret key', [], 'Modules.Axitrace.Admin'),
                        'name' => ModuleConfig::SECRET_KEY,
                        'desc' => $t->trans('Optional, for profit tracking: sends product costs and refunds. Stored encrypted and never shown again.', [], 'Modules.Axitrace.Admin') . ' ' . $secretState,
                    ],
                    [
                        'type' => 'checkbox',
                        'label' => '',
                        'name' => self::FIELD_SECRET_CLEAR,
                        'values' => [
                            'query' => [[
                                'id' => 'on',
                                'name' => $t->trans('Clear the secret key', [], 'Modules.Axitrace.Admin'),
                                'val' => '1',
                            ]],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $t->trans('Tracking domain', [], 'Modules.Axitrace.Admin'),
                        'name' => ModuleConfig::TRACKING_DOMAIN,
                        'class' => 'fixed-width-xxl',
                        'placeholder' => ModuleConfig::DEFAULT_TRACKING_DOMAIN,
                        'desc' => $t->trans('Host name only, without https:// and without a path. Default stat.axitrace.com, or your own subdomain set up in AxiTrace under Tracking & domains.', [], 'Modules.Axitrace.Admin'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $t->trans('Conversion value', [], 'Modules.Axitrace.Admin'),
                        'name' => ModuleConfig::VALUE_BASIS,
                        'options' => [
                            'query' => [
                                ['id' => ConversionValueBasis::GrossTotal->value, 'name' => $t->trans('Order total incl. VAT and shipping (default)', [], 'Modules.Axitrace.Admin')],
                                ['id' => ConversionValueBasis::GrossExclShipping->value, 'name' => $t->trans('Order total incl. VAT, excl. shipping', [], 'Modules.Axitrace.Admin')],
                                ['id' => ConversionValueBasis::NetTotal->value, 'name' => $t->trans('Order total excl. VAT, incl. shipping', [], 'Modules.Axitrace.Admin')],
                                ['id' => ConversionValueBasis::NetExclShipping->value, 'name' => $t->trans('Product revenue only - excl. VAT and shipping', [], 'Modules.Axitrace.Admin')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'desc' => $t->trans('Which order amount is reported as the purchase value. Applies to new orders only.', [], 'Modules.Axitrace.Admin'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $t->trans('Cookie consent mode', [], 'Modules.Axitrace.Admin'),
                        'name' => ModuleConfig::CONSENT_MODE,
                        'options' => [
                            'query' => [
                                ['id' => ConsentMode::Off->value, 'name' => $t->trans('Load immediately (default)', [], 'Modules.Axitrace.Admin')],
                                ['id' => ConsentMode::Browser->value, 'name' => $t->trans('Wait for consent - browser tracking only', [], 'Modules.Axitrace.Admin')],
                                ['id' => ConsentMode::All->value, 'name' => $t->trans('Wait for consent - browser tracking and server-side order events', [], 'Modules.Axitrace.Admin')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $t->trans('Consent cookie name', [], 'Modules.Axitrace.Admin'),
                        'name' => ModuleConfig::CONSENT_COOKIE,
                        'class' => 'fixed-width-xxl',
                        'desc' => $t->trans('The cookie your consent tool sets once the visitor accepts marketing or statistics cookies.', [], 'Modules.Axitrace.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $t->trans('Cash on delivery modules', [], 'Modules.Axitrace.Admin'),
                        'name' => ModuleConfig::COD_MODULES,
                        'class' => 'fixed-width-xxl',
                        'desc' => $t->trans('Technical names of payment modules whose orders are sent when they are placed, separated by commas.', [], 'Modules.Axitrace.Admin'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $t->trans('Customer groups excluded from tracking', [], 'Modules.Axitrace.Admin'),
                        'name' => self::FIELD_EXCLUDED_GROUPS_INPUT,
                        'id' => ModuleConfig::EXCLUDED_GROUPS,
                        'multiple' => true,
                        'size' => min(8, max(3, count($groupOptions))),
                        'options' => ['query' => $groupOptions, 'id' => 'id', 'name' => 'name'],
                        'desc' => $t->trans('Customers whose default group is selected are not tracked at all.', [], 'Modules.Axitrace.Admin'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $t->trans('Debug mode', [], 'Modules.Axitrace.Admin'),
                        'name' => ModuleConfig::DEBUG,
                        'is_bool' => true,
                        'values' => $yesNo,
                        'desc' => $t->trans('Verbose SDK console output in the storefront. Keep it off in production.', [], 'Modules.Axitrace.Admin'),
                    ],
                    // The module's CSRF token ({@see ActionToken}); its value is set by the module.
                    ['type' => 'hidden', 'name' => self::FIELD_ACTION_TOKEN],
                ],
                'submit' => [
                    'title' => $t->trans('Save', [], 'Modules.Axitrace.Admin'),
                    'name' => self::SUBMIT,
                ],
            ],
        ]];
    }

    private function validateTrackingDomain(string $domain): ?string
    {
        $t = $this->translator;
        $domain = strtolower($domain);
        if ($domain === '') {
            return null;
        }

        if (str_contains($domain, '://') || str_contains($domain, '/') || str_contains($domain, ':')
            || str_contains($domain, '?') || str_contains($domain, '#')) {
            return $t->trans('Enter the tracking domain as a host name only, without https:// and without a path, for example pixel.yourstore.com.', [], 'Modules.Axitrace.Admin');
        }

        if (EndpointResolver::isIpLiteral($domain)) {
            return $t->trans('The tracking domain must be a host name, not an IP address.', [], 'Modules.Axitrace.Admin');
        }

        if (preg_match(ModuleConfig::HOST_PATTERN, $domain) !== 1) {
            return $t->trans('The tracking domain is not a valid host name.', [], 'Modules.Axitrace.Admin');
        }

        return null;
    }

    private function set(string $key, string $value, ShopScope $scope): void
    {
        $this->store->set($key, $value, $scope->idShopGroup, $scope->idShop);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function flag(array $input, string $key): bool
    {
        return self::text($input, $key) === '1';
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     */
    private static function list(array $input, string $key): array
    {
        $value = $input[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $items[] = trim((string) $item);
            }
        }

        return $items;
    }
}
