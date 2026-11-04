<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\DbGatewayInterface;
use Axitrace\PrestaShop\Adapter\HttpTransportInterface;
use Axitrace\PrestaShop\Config\DeliveryHealth;
use Axitrace\PrestaShop\Config\EndpointResolver;
use Axitrace\PrestaShop\Config\FailureRecorder;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\SecretKeyVault;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Consent\ConsentGate;
use Axitrace\PrestaShop\EventId\Ga4TransactionEventId;
use Axitrace\PrestaShop\Exclusion\CustomerGroupExclusion;
use Axitrace\PrestaShop\Hook\Order\OrderStatusHook;
use Axitrace\PrestaShop\Hook\Order\RefundHook;
use Axitrace\PrestaShop\Hook\Order\StatusChange;
use Axitrace\PrestaShop\Http\IngestionClient;
use Axitrace\PrestaShop\Normalizer\OrderEventNormalizer;
use Axitrace\PrestaShop\Normalizer\PlatformInfo;
use Axitrace\PrestaShop\Normalizer\PurchaseCostResolver;
use Axitrace\PrestaShop\Normalizer\RefundEventNormalizer;
use Axitrace\PrestaShop\Purchase\BrowserPurchase;
use Axitrace\PrestaShop\Purchase\OrderRowRepository;
use Axitrace\PrestaShop\Purchase\PurchaseEligibility;
use Axitrace\PrestaShop\Purchase\PurchaseLedger;
use Axitrace\PrestaShop\Purchase\PurchaseOrder;
use Axitrace\PrestaShop\Purchase\PurchaseSender;
use Axitrace\PrestaShop\Refund\RefundLedger;
use Axitrace\PrestaShop\Refund\RefundSender;
use Axitrace\PrestaShop\Retry\FailedEventQueue;
use Axitrace\PrestaShop\Retry\LazyTrigger;
use Axitrace\PrestaShop\Retry\RetryLock;
use Axitrace\PrestaShop\Retry\RetryRunner;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;

/**
 * The purchase pipeline wired like ModuleServices, over in-memory configuration and tables, a fake
 * HTTP transport and a developer endpoint override pointing at a sink.
 */
final class PurchasePipeline
{
    public const PK = 'pk_live_0123456789abcdef0123456789abcdef';
    public const SK = 'sk_live_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    public const SINK = 'http://axitrace-sink:8080';

    public InMemoryConfigurationStore $store;
    public InMemoryModuleDb $db;
    public FakeOrderReader $reader;
    public FakeHttpTransport $transport;
    public SpyLogger $logger;
    public MutableClock $clock;
    public FakeScheduler $scheduler;
    public FakeRefundReader $refundReader;

    /** @var array<int, int> shop id => shop group id */
    public array $shopGroups = [];

    /** Endpoints of every sender; null = the developer override pointing at {@see SINK}. */
    public ?EndpointResolver $endpoints = null;

    public function __construct(?FakeOrderReader $reader = null)
    {
        $this->store = new InMemoryConfigurationStore();
        $this->store->setGlobal(ModuleConfig::ENABLED, '1');
        $this->store->setGlobal(ModuleConfig::PUBLIC_KEY, self::PK);
        $this->store->setGlobal(ModuleConfig::COD_MODULES, ModuleConfig::DEFAULT_COD_MODULES);
        $this->store->setGlobal(ModuleConfig::RETRY_LOCK, '0');
        $this->db = new InMemoryModuleDb();
        $this->db->configuration = $this->store;
        $this->scheduler = new FakeScheduler();
        $this->reader = $reader ?? new FakeOrderReader();
        $this->refundReader = new FakeRefundReader();
        $this->transport = new FakeHttpTransport();
        $this->logger = new SpyLogger();
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-11-03T22:00:00+00:00'));
    }

    public function config(): ModuleConfig
    {
        return new ModuleConfig($this->store);
    }

    public function failures(): FailureRecorder
    {
        return new FailureRecorder($this->store, $this->logger, $this->clock);
    }

    public function vault(): SecretKeyVault
    {
        return new SecretKeyVault($this->store, new FakeCipher(), $this->failures());
    }

    public function health(): DeliveryHealth
    {
        return new DeliveryHealth($this->store, $this->clock);
    }

    public function sender(?HttpTransportInterface $transport = null): PurchaseSender
    {
        return new PurchaseSender(
            new IngestionClient($transport ?? $this->transport),
            $this->config(),
            $this->vault(),
            $this->endpointResolver(),
            $this->failures(),
            $this->health(),
        );
    }

    public function endpointResolver(): EndpointResolver
    {
        return $this->endpoints ?? new EndpointResolver(new FakeEnvironment(true, [EndpointResolver::ENV_API_OVERRIDE => self::SINK]), new FakeHostResolver());
    }

    public function normalizer(): OrderEventNormalizer
    {
        return new OrderEventNormalizer(new PlatformInfo('0.0.1', '8.1.7', '8.1.34'), $this->clock, new PurchaseCostResolver(), $this->logger);
    }

    public function hook(?DbGatewayInterface $db = null, ?HttpTransportInterface $transport = null): OrderStatusHook
    {
        $db ??= $this->db;

        return new OrderStatusHook(
            $this->config(),
            $this->reader,
            new OrderRowRepository($db),
            new CartSnapshotRepository($db),
            new PurchaseEligibility(),
            new PurchaseLedger($db),
            new CustomerGroupExclusion($this->config()),
            new ConsentGate(),
            $this->normalizer(),
            $this->sender($transport),
            $this->refundHook($db, $transport),
            $this->queue($db),
            $this->failures(),
            $this->logger,
            $this->clock,
        );
    }

    public function refundHook(?DbGatewayInterface $db = null, ?HttpTransportInterface $transport = null): RefundHook
    {
        $db ??= $this->db;

        return new RefundHook(
            $this->config(),
            $this->vault(),
            new OrderRowRepository($db),
            $this->reader,
            $this->refundReader,
            new RefundEventNormalizer(),
            new RefundLedger($db),
            $this->refundSender($transport),
            $this->queue($db),
            $this->failures(),
            $this->logger,
            $this->clock,
        );
    }

    public function refundSender(?HttpTransportInterface $transport = null): RefundSender
    {
        return new RefundSender(
            new IngestionClient($transport ?? $this->transport),
            $this->config(),
            $this->vault(),
            $this->endpointResolver(),
            $this->failures(),
            $this->health(),
        );
    }

    public function refundLedger(): RefundLedger
    {
        return new RefundLedger($this->db);
    }

    public function queue(?DbGatewayInterface $db = null): FailedEventQueue
    {
        return new FailedEventQueue($db ?? $this->db, $this->store);
    }

    public function lock(): RetryLock
    {
        return new RetryLock($this->db, $this->store);
    }

    public function runner(?HttpTransportInterface $transport = null): RetryRunner
    {
        return new RetryRunner(
            $this->queue(),
            $this->sender($transport),
            $this->refundSender($transport),
            new PurchaseLedger($this->db),
            new RefundLedger($this->db),
            new CartSnapshotRepository($this->db),
            new FakeShopGroupResolver($this->shopGroups),
            $this->failures(),
            $this->logger,
            $this->clock,
        );
    }

    public function lazyTrigger(): LazyTrigger
    {
        return new LazyTrigger($this->queue(), $this->lock(), $this->runner(), $this->scheduler, $this->failures(), $this->logger, $this->clock);
    }

    public function browserPurchase(): BrowserPurchase
    {
        return new BrowserPurchase(
            $this->config(),
            $this->reader,
            new OrderRowRepository($this->db),
            new PurchaseEligibility(),
            new CustomerGroupExclusion($this->config()),
            $this->normalizer(),
        );
    }

    /**
     * What actionValidateOrder records (OrderPlacedHook): the NEW row with its event id.
     *
     * @param array<string, mixed>|null $snapshot
     */
    public function place(PurchaseOrder $order, ?array $snapshot = null): void
    {
        $this->reader->add($order);
        (new OrderRowRepository($this->db))->insertNew(
            $order->idOrder,
            $order->idShop,
            $order->idShopGroup,
            $order->reference,
            Ga4TransactionEventId::fromTransaction(self::PK, (string) $order->idOrder),
            $snapshot,
            $this->clock->now(),
        );
    }

    public function status(int $idOrder, int $stateId, mixed $paid): void
    {
        $this->hook()->handle(StatusChange::fromHookValues($idOrder, $stateId, $paid));
    }

    /**
     * @return array{code: string, message: string, first_at: string, last_at: string, count: int}|null
     */
    public function lastFailure(int $idShopGroup = 1, int $idShop = 1): ?array
    {
        return $this->failures()->last(ShopScope::shop($idShopGroup, $idShop));
    }
}
