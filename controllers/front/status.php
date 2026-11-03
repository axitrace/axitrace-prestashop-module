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

use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Hook\Front\StatusResponder;
use Axitrace\PrestaShop\ModuleServices;

/**
 * GET /module/axitrace/status -> {"tracked": true|false}, never cached. The storefront script asks
 * it before loading the SDK when customer groups are excluded from tracking, so the cacheable page
 * HTML never carries anything about the visitor.
 */
class AxitraceStatusModuleFrontController extends ModuleFrontController
{
    /** @var Axitrace */
    public $module;

    public function initContent(): void
    {
        // No template: display() writes the JSON answer.
    }

    public function display(): bool
    {
        foreach (StatusResponder::HEADERS as $name => $value) {
            header($name . ': ' . $value);
        }

        $customer = $this->context->customer;
        $groupId = $customer instanceof Customer && $customer->isLogged() ? (int) $customer->id_default_group : null;

        try {
            $body = (new ModuleServices($this->module))->statusResponder()->body($groupId, ShopScope::context());
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(sprintf('AxiTrace: status controller failed (%s: %s)', $e::class, $e->getMessage()), 3, null, 'Axitrace', null, false);
            http_response_code(500);
            $body = '{"tracked":false,"error":"status_unavailable"}';
        }

        $this->ajaxRender($body);

        return true;
    }
}
