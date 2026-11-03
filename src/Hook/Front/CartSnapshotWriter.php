<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Hook\Front;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\RequestContextInterface;
use Axitrace\PrestaShop\Config\ModuleConfig;
use Axitrace\PrestaShop\Config\ShopScope;
use Axitrace\PrestaShop\Snapshot\CartSnapshotRepository;
use Axitrace\PrestaShop\Snapshot\SnapshotBuilder;

/**
 * `actionFrontControllerSetMedia` on the cart and checkout pages: records the buyer's
 * attribution snapshot for the cart, in the buyer's own request (A24). The row is written only
 * when its content changed (hash). Click ids seen earlier for the same cart are kept when the
 * current request no longer carries them (a URL-only click id is in one request only).
 */
final class CartSnapshotWriter
{
    /** php_self of the pages that snapshot (T1: identical on 8.1.7 and 9.2.0). */
    public const PAGES = ['cart', 'order'];

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly SnapshotBuilder $builder,
        private readonly CartSnapshotRepository $snapshots,
        private readonly RequestContextInterface $request,
        private readonly ClockInterface $clock,
    ) {
    }

    /** True when a row was written, false when nothing had to be (or may be) written. */
    public function write(string $phpSelf, int $idCart, int $idShop, ShopScope $scope): bool
    {
        if ($idCart <= 0 || !in_array($phpSelf, self::PAGES, true) || !$this->config->isEnabled($scope)) {
            return false;
        }

        $now = $this->clock->now();
        $snapshot = $this->builder->build($this->request, $this->config->consentCookie($scope), (int) $now->format('Uv'));

        $previous = $this->snapshots->find($idCart);
        if ($previous !== null) {
            $snapshot = self::keepEarlierClickIds($previous['snapshot'], $snapshot);
        }

        $hash = SnapshotBuilder::hash($snapshot);
        if ($previous !== null && hash_equals($previous['hash'], $hash)) {
            return false;
        }

        $this->snapshots->save($idCart, $idShop, $snapshot, $hash, $now);

        return true;
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     *
     * @return array<string, mixed>
     */
    private static function keepEarlierClickIds(array $previous, array $current): array
    {
        $earlier = is_array($previous['clickIds'] ?? null) ? $previous['clickIds'] : [];
        $now = is_array($current['clickIds'] ?? null) ? $current['clickIds'] : [];
        $merged = array_replace($earlier, $now);
        if ($merged === []) {
            return $current;
        }

        ksort($merged);
        $current['clickIds'] = $merged;
        ksort($current);

        return $current;
    }
}
