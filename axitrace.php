<?php
/**
 * AxiTrace for PrestaShop.
 *
 * @author    AxiTrace
 * @copyright AxiTrace
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Axitrace\PrestaShop\Admin\ConfigFormHandler;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Hook\Front\PageContextRenderer;
use Axitrace\PrestaShop\Install\VersionGuard;
use Axitrace\PrestaShop\ModuleServices;

class Axitrace extends Module
{
    private ?ModuleServices $services = null;

    public function __construct()
    {
        $this->name = 'axitrace';
        $this->tab = 'analytics_stats';
        $this->version = '0.0.1';
        $this->author = 'AxiTrace';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => VersionGuard::MIN_PRESTASHOP, 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('AxiTrace', [], 'Modules.Axitrace.Admin');
        $this->description = $this->trans('Server-side conversion tracking for Meta, Google Ads, GA4, TikTok and more, with profit tracking.', [], 'Modules.Axitrace.Admin');
        $this->confirmUninstall = $this->trans('Uninstall AxiTrace? Its settings and tables, including events waiting for a retry, are deleted.', [], 'Modules.Axitrace.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        if (!VersionGuard::isSupported(_PS_VERSION_, PHP_VERSION_ID)) {
            $this->_errors[] = $this->trans('AxiTrace requires PrestaShop 8.1 or newer and PHP 8.1 or newer.', [], 'Modules.Axitrace.Admin');

            return false;
        }

        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (!parent::install()) {
            return false;
        }

        try {
            $this->services()->schemaInstaller()->install();
            $this->services()->configInstaller()->install();
        } catch (\Throwable $e) {
            return $this->failInstallStep('install', $e);
        }

        $failed = $this->services()->hookRegistrar()->register(fn (string $hook): bool => (bool) $this->registerHook($hook));
        if ($failed !== []) {
            $this->_errors[] = $this->trans('These hooks could not be registered: %hooks%', ['%hooks%' => implode(', ', $failed)], 'Modules.Axitrace.Admin');

            return false;
        }

        return true;
    }

    public function uninstall(): bool
    {
        try {
            $this->services()->schemaInstaller()->uninstall();
            $this->services()->configInstaller()->uninstall();
        } catch (\Throwable $e) {
            return $this->failInstallStep('uninstall', $e);
        }

        return (bool) parent::uninstall();
    }

    public function getContent(): string
    {
        $services = $this->services();
        $handler = $services->configFormHandler();
        $scope = ShopScope::context();
        $groups = $this->customerGroups();
        $output = '';
        $submitted = null;

        if (Tools::isSubmit(ConfigFormHandler::SUBMIT)) {
            $submitted = [];
            foreach (ConfigFormHandler::FIELDS as $field) {
                $submitted[$field] = Tools::getValue($field);
            }

            $errors = $handler->validate($submitted, array_column($groups, 'id'));
            if ($errors === []) {
                try {
                    $handler->save($submitted, $scope);
                    $output .= $this->displayConfirmation($this->trans('Settings saved.', [], 'Modules.Axitrace.Admin'));
                    $submitted = null;
                } catch (\Throwable $e) {
                    PrestaShopLogger::addLog(sprintf('AxiTrace: settings could not be saved (%s: %s)', $e::class, $e->getMessage()), 3, null, 'Axitrace', null, true);
                    $output .= $this->displayError($this->trans('The settings could not be saved: %error%', ['%error%' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')], 'Modules.Axitrace.Admin'));
                }
            } else {
                foreach ($errors as $error) {
                    $output .= $this->displayError($error);
                }
            }
        }

        $secretKey = $services->secretKeyVault()->read($scope);
        $endpoints = $services->endpointResolver()->resolve($services->config()->trackingDomain($scope));

        $this->smarty()->assign([
            'axitrace_errors' => $services->configPageNotices()->errors($endpoints, $secretKey),
        ]);

        $values = $handler->currentValues($scope);
        if ($submitted !== null) {
            $values = $this->submittedValues($values, $submitted);
        }

        return $output
            . $this->display(__FILE__, 'views/templates/admin/configure.tpl')
            . $this->renderForm($handler->formDefinition($groups, $secretKey->state), $values);
    }

    /*
     * Hooks. Registered at install (HookRegistrar::HOOKS); the order, refund and GDPR behaviour arrives
     * with later releases.
     */

    /**
     * @param array<string, mixed> $params
     */
    public function hookDisplayHeader(array $params): string
    {
        try {
            $phpSelf = $this->phpSelf();
            $scope = ShopScope::context();
            $json = $this->services()->headerRenderer()->render(
                $scope,
                $phpSelf,
                (string) $this->version,
                $this->statusUrl(),
            );
            if ($json === '') {
                return '';
            }

            $html = $this->renderJsonTemplate('header.tpl', $json);

            $cartType = PageContextRenderer::cartPageType($phpSelf);
            if ($cartType !== null) {
                $basis = $this->services()->config()->valueBasis($scope);
                $cart = $this->services()->storefrontReader($this->context)->cart($basis->includesTax(), $basis->includesShipping());
                if ($cart !== null) {
                    $renderer = $this->services()->pageContextRenderer();
                    $html .= $this->renderJsonTemplate('context.tpl', $renderer->encode($renderer->cart($cartType, $cart)));
                }
            }

            return $html;
        } catch (\Throwable $e) {
            return $this->storefrontFailure('displayHeader', $e);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookDisplayFooterProduct(array $params): string
    {
        try {
            $scope = ShopScope::context();
            $config = $this->services()->config();
            if (!$config->isEnabled($scope)) {
                return '';
            }

            $product = $params['product'] ?? null;
            $productId = (int) (self::field($product, 'id_product') ?? Tools::getValue('id_product'));
            $variantId = (int) (self::field($product, 'id_product_attribute') ?? Tools::getValue('id_product_attribute'));
            if ($productId <= 0) {
                return '';
            }

            $view = $this->services()->storefrontReader($this->context)->product($productId, $variantId, $config->valueBasis($scope)->includesTax());
            if ($view === null) {
                return '';
            }

            $renderer = $this->services()->pageContextRenderer();

            return $this->renderJsonTemplate('context.tpl', $renderer->encode($renderer->product($view)));
        } catch (\Throwable $e) {
            return $this->storefrontFailure('displayFooterProduct', $e);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookDisplayOrderConfirmation(array $params): string
    {
        try {
            if (!$this->services()->config()->isEnabled(ShopScope::context())) {
                return '';
            }

            $view = $this->services()->storefrontReader($this->context)->confirmation($params['order'] ?? null);
            if ($view === null) {
                return '';
            }

            $renderer = $this->services()->pageContextRenderer();

            return $this->renderJsonTemplate('context.tpl', $renderer->encode($renderer->confirmation($view)));
        } catch (\Throwable $e) {
            return $this->storefrontFailure('displayOrderConfirmation', $e);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionFrontControllerSetMedia(array $params): void
    {
        try {
            if (!$this->services()->config()->isEnabled(ShopScope::context())) {
                return;
            }

            $controller = $this->context->controller;
            if ($controller instanceof FrontController) {
                // Bottom of the page, after core.js and the theme (priority 200): the script
                // subscribes to prestashop.on('updateCart') when it runs.
                $controller->registerJavascript(
                    'module-axitrace-storefront',
                    'modules/' . $this->name . '/views/js/axitrace-storefront.js',
                    ['position' => 'bottom', 'priority' => 200, 'version' => (string) $this->version],
                );
            }
        } catch (\Throwable $e) {
            $this->storefrontFailure('actionFrontControllerSetMedia', $e);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionAdminControllerSetMedia(array $params): void
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionValidateOrder(array $params): void
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionOrderStatusPostUpdate(array $params): void
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionOrderSlipAdd(array $params): void
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionProductCancel(array $params): void
    {
    }

    /**
     * psgdpr only checks that the module is registered on this hook.
     *
     * @param array<string, mixed> $params
     */
    public function hookRegisterGDPRConsent(array $params): void
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionExportGDPRData(array $params): string
    {
        return (string) json_encode([]);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionDeleteGDPRCustomer(array $params): string
    {
        return (string) json_encode(true);
    }

    private function services(): ModuleServices
    {
        return $this->services ??= new ModuleServices($this);
    }

    private function phpSelf(): string
    {
        $controller = $this->context->controller;

        return $controller instanceof FrontController ? (string) $controller->php_self : '';
    }

    private function smarty(): Smarty
    {
        $smarty = $this->context->smarty;
        if ($smarty === null) {
            throw new RuntimeException('Smarty is not initialised.');
        }

        return $smarty;
    }

    private function statusUrl(): string
    {
        $link = $this->context->link;
        if (!$link instanceof Link) {
            throw new RuntimeException('The PrestaShop link service is not initialised.');
        }

        return (string) $link->getModuleLink((string) $this->name, 'status');
    }

    private function renderJsonTemplate(string $template, string $json): string
    {
        $this->smarty()->assign('axitrace_json', $json);

        return (string) $this->display(__FILE__, 'views/templates/hook/' . $template);
    }

    /** A value of a hook parameter that may be an array or a PrestaShop lazy array. */
    private static function field(mixed $source, string $key): mixed
    {
        if (is_array($source) || $source instanceof ArrayAccess) {
            return $source[$key] ?? null;
        }

        return null;
    }

    /**
     * A storefront hook must never break the shop page: the failure is logged with class and
     * message and recorded as the module's last failure (shown in the back office), and the
     * hook renders nothing.
     */
    private function storefrontFailure(string $hook, \Throwable $e): string
    {
        $message = sprintf('%s failed (%s: %s)', $hook, $e::class, $e->getMessage());
        try {
            $this->services()->failureRecorder()->record('storefront_' . $hook, $message, ShopScope::context());
        } catch (\Throwable $recordError) {
            PrestaShopLogger::addLog(sprintf('AxiTrace: %s; recording the failure failed too (%s: %s)', $message, $recordError::class, $recordError->getMessage()), 3, null, 'Axitrace', null, false);
        }

        return '';
    }

    private function failInstallStep(string $step, \Throwable $e): bool
    {
        $message = sprintf('%s: %s', $e::class, $e->getMessage());
        PrestaShopLogger::addLog(sprintf('AxiTrace: %s failed (%s)', $step, $message), 3, null, 'Axitrace', null, true);
        $this->_errors[] = $this->trans('AxiTrace could not complete the %step%: %error%', ['%step%' => $step, '%error%' => $message], 'Modules.Axitrace.Admin');

        return false;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function customerGroups(): array
    {
        $groups = [];
        foreach (Group::getGroups((int) ($this->context->language->id ?? 0), (int) ($this->context->shop->id ?? 0)) as $row) {
            $groups[] = ['id' => (int) $row['id_group'], 'name' => (string) $row['name']];
        }

        return $groups;
    }

    /**
     * Shows the submitted values again after a validation error (never the secret key).
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed>
     */
    private function submittedValues(array $values, array $submitted): array
    {
        foreach ($submitted as $field => $value) {
            if ($field === ModuleConfig::SECRET_KEY) {
                continue;
            }
            if ($field === ModuleConfig::EXCLUDED_GROUPS) {
                $values[ConfigFormHandler::FIELD_EXCLUDED_GROUPS_INPUT] = is_array($value) ? $value : [];
                continue;
            }
            $values[$field] = is_scalar($value) ? (string) $value : '';
        }

        return $values;
    }

    /**
     * The languages HelperForm expects (AdminController::getLanguages() shape), read without the
     * controller: on 9.x the module page runs behind a Symfony bridge that lacks that method.
     *
     * @return list<array<string, mixed>>
     */
    private function formLanguages(): array
    {
        $default = (int) Configuration::get('PS_LANG_DEFAULT');
        $languages = [];
        foreach (Language::getLanguages(false) as $language) {
            if (!is_array($language)) {
                continue;
            }
            $language['is_default'] = (int) ((int) ($language['id_lang'] ?? 0) === $default);
            $languages[] = $language;
        }

        return $languages;
    }

    /**
     * @param list<array<string, mixed>> $definition
     * @param array<string, mixed>       $values
     */
    private function renderForm(array $definition, array $values): string
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $helper->submit_action = ConfigFormHandler::SUBMIT;
        $helper->tpl_vars = [
            'fields_value' => $values,
            'languages' => $this->formLanguages(),
            'id_language' => (int) ($this->context->language->id ?? 0),
        ];

        return $helper->generateForm($definition);
    }
}
