<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Domain;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\ClockInterface;
use Axitrace\PrestaShop\Adapter\ConfigurationStoreInterface;
use Axitrace\PrestaShop\Adapter\DeferredTaskSchedulerInterface;
use Axitrace\PrestaShop\Config\ModuleConfig;

/**
 * The daily store domain claim without a system cron, like the retry queue's lazy trigger: an
 * ordinary back-office, storefront (PHP-FPM only, after the response) or cron URL request runs
 * {@see StoreDomainClaimer::claimAll()} at its end once AXITRACE_DOMAIN_CLAIM_DUE_AT (global) is
 * reached. A shop that never claimed (no due time stored, e.g. right after an upgrade) claims at
 * the first such request, so it does not wait for the merchant to open the settings.
 */
final class StoreDomainClaimTrigger
{
    public function __construct(
        private readonly ConfigurationStoreInterface $store,
        private readonly StoreDomainClaimer $claimer,
        private readonly DeferredTaskSchedulerInterface $scheduler,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param bool $storefront true for a storefront request (runs only when the response can be finished first)
     *
     * @return bool true when a claim was scheduled for the end of this request
     */
    public function maybeSchedule(bool $storefront): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $due = trim((string) $this->store->get(ModuleConfig::DOMAIN_CLAIM_DUE_AT, 0, 0));
        if ($due !== '' && ctype_digit($due) && (int) $due > $now) {
            return false;
        }
        if ($storefront && !$this->scheduler->canFinishResponseFirst()) {
            return false;
        }

        // Taken before the claim runs, so the requests arriving meanwhile do not claim again.
        $this->store->setGlobal(ModuleConfig::DOMAIN_CLAIM_DUE_AT, (string) ($now + StoreDomainClaimer::INTERVAL_SECONDS));
        $this->scheduler->defer(function (): void {
            $this->claimer->claimAll();
        });

        return true;
    }

    /** Makes the next ordinary request claim (module upgrade). */
    public function claimSoon(): void
    {
        $this->store->setGlobal(ModuleConfig::DOMAIN_CLAIM_DUE_AT, '0');
    }
}
