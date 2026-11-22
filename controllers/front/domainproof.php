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

use Axitrace\PrestaShop\Domain\DomainProofResponder;
use Axitrace\PrestaShop\ModuleServices;

/**
 * GET /index.php?fc=module&module=axitrace&controller=domainproof&nonce=<nonce>: the store domain
 * proof AxiTrace fetches after the module claimed the shop's domain (DomainProofResponder), answered
 * for the shop the requested URL belongs to. A machine endpoint: no session, login, maintenance
 * page, country restriction or canonical redirect stands in its way.
 */
class AxitraceDomainproofModuleFrontController extends ModuleFrontController
{
    /** @var Axitrace */
    public $module;

    public function initContent(): void
    {
        // No template: display() writes the JSON answer.
    }

    /**
     * PrestaShop answers a request that accepts application/json in ajax mode (Controller::isAjax()
     * overrides $ajax in the constructor) and then calls displayAjax(), never display(): without this
     * such a request got an empty text/html 200. AxiTrace's proof fetcher sends that header.
     */
    public function displayAjax(): void
    {
        $this->display();
    }

    public function display(): bool
    {
        foreach (DomainProofResponder::HEADERS as $name => $value) {
            header($name . ': ' . $value);
        }

        try {
            $shop = $this->context->shop;
            $idShop = $shop instanceof Shop ? (int) $shop->id : 0;
            $idShopGroup = $shop instanceof Shop ? (int) $shop->id_shop_group : 0;
            $services = new ModuleServices($this->module);
            $nonce = Tools::getValue('nonce');
            $answer = $services->domainProofResponder()->answer(
                is_string($nonce) ? $nonce : '',
                $services->shopHosts($idShopGroup, $idShop),
                (string) Tools::getHttpHost(false, false, true),
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(sprintf('AxiTrace: domain proof controller failed (%s: %s)', $e::class, $e->getMessage()), 3, null, 'Axitrace', null, false);
            $answer = ['status' => 500, 'body' => '{"error":"proof_unavailable"}'];
        }

        http_response_code($answer['status']);
        $this->ajaxRender($answer['body']);

        return true;
    }

    /** AxiTrace must reach the proof while the shop is in maintenance mode. */
    protected function displayMaintenancePage(): void
    {
    }

    /** AxiTrace's servers must reach the proof whatever the shop's geolocation restrictions. */
    protected function displayRestrictedCountryPage(): void
    {
    }

    /** The proof is answered on the requested host; a redirect to another URL would break the signature. */
    protected function canonicalRedirection($canonicalUrl = ''): void
    {
    }
}
