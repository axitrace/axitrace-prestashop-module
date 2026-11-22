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

use Axitrace\PrestaShop\ModuleServices;
use Axitrace\PrestaShop\Retry\CronResponder;

/**
 * GET /module/axitrace/cron?token=<AXITRACE_CRON_TOKEN> - optional cron URL that sends the queued
 * deliveries. The token is compared in constant time (hash_equals); a wrong or missing one is 403.
 */
class AxitraceCronModuleFrontController extends ModuleFrontController
{
    /** @var Axitrace */
    public $module;

    public function initContent(): void
    {
        // No template: display() writes the plain-text answer.
    }

    /**
     * PrestaShop answers a request that accepts application/json in ajax mode (Controller::isAjax()
     * overrides $ajax in the constructor) and then calls displayAjax(), never display(): without this
     * such a request got an empty text/html 200 (some cron services send that header).
     */
    public function displayAjax(): void
    {
        $this->display();
    }

    public function display(): bool
    {
        foreach (CronResponder::HEADERS as $name => $value) {
            header($name . ': ' . $value);
        }

        try {
            $services = new ModuleServices($this->module);
            $expected = $services->config()->cronToken();
            $token = Tools::getValue('token');
            $authorized = $expected !== '' && is_string($token) && hash_equals($expected, $token);
            $answer = $services->cronResponder()->respond($authorized);
            if ($authorized) {
                // The daily store domain claim, when due, runs at the end of this request.
                $services->storeDomainClaimTrigger()->maybeSchedule(false);
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(sprintf('AxiTrace: cron controller failed (%s: %s)', $e::class, $e->getMessage()), 3, null, 'Axitrace', null, false);
            $answer = ['status' => 500, 'body' => "error\n"];
        }

        http_response_code($answer['status']);
        $this->ajaxRender($answer['body']);

        return true;
    }
}
