<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\DnsHostResolver;
use Axitrace\PrestaShop\Adapter\HttpTransport;
use Axitrace\PrestaShop\Adapter\ModuleTranslator;
use Axitrace\PrestaShop\Adapter\NativeSecureRandom;
use Axitrace\PrestaShop\Adapter\PhpEncryptionCipher;
use Axitrace\PrestaShop\Adapter\PrestaShopConfigurationStore;
use Axitrace\PrestaShop\Adapter\PrestaShopDbGateway;
use Axitrace\PrestaShop\Adapter\PrestaShopLoggerAdapter;
use Axitrace\PrestaShop\Adapter\PrestaShopOrderReader;
use Axitrace\PrestaShop\Adapter\PrestaShopRequestContext;
use Axitrace\PrestaShop\Adapter\PrestaShopStorefrontReader;
use Axitrace\PrestaShop\Adapter\ProcessEnvironment;
use Axitrace\PrestaShop\Adapter\SystemClock;
use Axitrace\PrestaShop\Admin\ConfigFormHandler;
use Axitrace\PrestaShop\Admin\ConfigPageNotices;
use Axitrace\PrestaShop\Config\DeliveryHealth;
use Axitrace\PrestaShop\Config\EndpointResolver;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\SecretKeyVault;
use Axitrace\PrestaShop\Consent\ConsentGate;
use Axitrace\PrestaShop\Exclusion\CustomerGroupExclusion;
use Axitrace\PrestaShop\Hook\Front\CartSnapshotWriter;
use Axitrace\PrestaShop\Hook\Front\HeaderRenderer;
use Axitrace\PrestaShop\Hook\Front\PageContextRenderer;
use Axitrace\PrestaShop\Hook\Front\StatusResponder;
use Axitrace\PrestaShop\Hook\Order\OrderPlacedHook;
use Axitrace\PrestaShop\Hook\Order\OrderStatusHook;
use Axitrace\PrestaShop\Http\IngestionClient;
use Axitrace\PrestaShop\Install\ConfigInstaller;
use Axitrace\PrestaShop\Install\HookRegistrar;
use Axitrace\PrestaShop\Install\SchemaInstaller;
use Axitrace\PrestaShop\Normalizer\ClickIdReader;
use Axitrace\PrestaShop\Normalizer\OrderEventNormalizer;
use Axitrace\PrestaShop\Normalizer\PlatformInfo;
use Axitrace\PrestaShop\Purchase\BrowserPurchase;
use Axitrace\PrestaShop\Purchase\OrderRowRepository;
use Axitrace\PrestaShop\Purchase\PurchaseEligibility;
use Axitrace\PrestaShop\Purchase\PurchaseLedger;
use Axitrace\PrestaShop\Purchase\PurchaseSender;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;
use Axitrace\PrestaShop\Snapshot\SnapshotBuilder;

/**
 * Composition root: the one place that wires the PrestaShop adapters into the module services.
 * PrestaShop instantiates modules itself, so constructor injection starts here.
 */
final class ModuleServices
{
    private ?PrestaShopDbGateway $db = null;
    private ?PrestaShopConfigurationStore $store = null;
    private ?ModuleConfig $config = null;
    private ?SecretKeyVault $vault = null;

    public function __construct(private readonly \Module $module)
    {
    }

    public function config(): ModuleConfig
    {
        return $this->config ??= new ModuleConfig($this->store());
    }

    public function secretKeyVault(): SecretKeyVault
    {
        return $this->vault ??= new SecretKeyVault(
            $this->store(),
            new PhpEncryptionCipher(),
            $this->failureRecorder(),
        );
    }

    public function endpointResolver(): EndpointResolver
    {
        return new EndpointResolver(new ProcessEnvironment(), new DnsHostResolver());
    }

    public function configFormHandler(): ConfigFormHandler
    {
        return new ConfigFormHandler($this->store(), $this->config(), $this->secretKeyVault(), $this->translator());
    }

    public function configPageNotices(): ConfigPageNotices
    {
        return new ConfigPageNotices($this->translator());
    }

    public function schemaInstaller(): SchemaInstaller
    {
        return new SchemaInstaller($this->db());
    }

    public function configInstaller(): ConfigInstaller
    {
        return new ConfigInstaller($this->store(), new NativeSecureRandom());
    }

    public function hookRegistrar(): HookRegistrar
    {
        return new HookRegistrar();
    }

    public function headerRenderer(): HeaderRenderer
    {
        return new HeaderRenderer($this->config(), $this->endpointResolver(), $this->exclusion());
    }

    public function pageContextRenderer(): PageContextRenderer
    {
        return new PageContextRenderer();
    }

    public function statusResponder(): StatusResponder
    {
        return new StatusResponder($this->config(), $this->exclusion());
    }

    public function storefrontReader(\Context $context): PrestaShopStorefrontReader
    {
        return new PrestaShopStorefrontReader($context);
    }

    public function failureRecorder(): FailureRecorder
    {
        return new FailureRecorder($this->store(), new PrestaShopLoggerAdapter(), new SystemClock());
    }

    public function cartSnapshotWriter(): CartSnapshotWriter
    {
        return new CartSnapshotWriter($this->config(), $this->snapshotBuilder(), $this->cartSnapshots(), new PrestaShopRequestContext(), new SystemClock());
    }

    public function orderPlacedHook(): OrderPlacedHook
    {
        return new OrderPlacedHook(
            $this->config(),
            $this->cartSnapshots(),
            new OrderRowRepository($this->db()),
            $this->snapshotBuilder(),
            new PrestaShopRequestContext(),
            $this->failureRecorder(),
            new PrestaShopLoggerAdapter(),
            new SystemClock(),
        );
    }

    public function orderStatusHook(): OrderStatusHook
    {
        return new OrderStatusHook(
            $this->config(),
            new PrestaShopOrderReader(),
            new OrderRowRepository($this->db()),
            $this->cartSnapshots(),
            new PurchaseEligibility(),
            $this->purchaseLedger(),
            $this->exclusion(),
            new ConsentGate(),
            $this->orderEventNormalizer(),
            $this->purchaseSender(),
            $this->failureRecorder(),
            new PrestaShopLoggerAdapter(),
            new SystemClock(),
        );
    }

    public function browserPurchase(): BrowserPurchase
    {
        return new BrowserPurchase(
            $this->config(),
            new PrestaShopOrderReader(),
            new OrderRowRepository($this->db()),
            new PurchaseEligibility(),
            $this->exclusion(),
            $this->orderEventNormalizer(),
        );
    }

    public function purchaseSender(): PurchaseSender
    {
        return new PurchaseSender(
            new IngestionClient(new HttpTransport()),
            $this->config(),
            $this->secretKeyVault(),
            $this->endpointResolver(),
            $this->failureRecorder(),
            new DeliveryHealth($this->store(), new SystemClock()),
        );
    }

    public function purchaseLedger(): PurchaseLedger
    {
        return new PurchaseLedger($this->db());
    }

    public function cartSnapshots(): CartSnapshotRepository
    {
        return new CartSnapshotRepository($this->db());
    }

    private function orderEventNormalizer(): OrderEventNormalizer
    {
        return new OrderEventNormalizer(
            new PlatformInfo((string) $this->module->version, _PS_VERSION_, PHP_VERSION),
            new SystemClock(),
        );
    }

    private function snapshotBuilder(): SnapshotBuilder
    {
        return new SnapshotBuilder(new ClickIdReader(), new ConsentGate());
    }

    private function exclusion(): CustomerGroupExclusion
    {
        return new CustomerGroupExclusion($this->config());
    }

    private function translator(): ModuleTranslator
    {
        return new ModuleTranslator($this->module);
    }

    private function store(): PrestaShopConfigurationStore
    {
        return $this->store ??= new PrestaShopConfigurationStore($this->db());
    }

    private function db(): PrestaShopDbGateway
    {
        return $this->db ??= new PrestaShopDbGateway();
    }
}
