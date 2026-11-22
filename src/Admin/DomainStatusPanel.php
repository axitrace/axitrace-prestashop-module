<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Adapter\TranslatorInterface;
use Axitrace\PrestaShop\Domain\ShopHosts;
use Axitrace\PrestaShop\Domain\StoreDomainStatus;

/**
 * "Store domain" on the configuration page: for every shop host, in one plain sentence, whether
 * AxiTrace accepts the browser events of the store, plus the "Verify store domain" button.
 *
 * Why: while the domain is not verified AxiTrace drops every page view, add to cart and checkout
 * of the store, and purchases still arrive from the server, so nothing else looks broken.
 */
final class DomainStatusPanel
{
    /** "Verify store domain": a back-office POST carrying the module's action token. */
    public const SUBMIT_VERIFY = 'submitAxitraceVerifyDomain';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @param list<array{shop: ShopHosts, status: StoreDomainStatus|null}> $shops {@see \Axitrace\PrestaShop\Domain\StoreDomainClaimer::lastStatuses()}
     *
     * @return list<array{host: string, state: string, message: string}> state: ok, warning or error
     */
    public function rows(array $shops): array
    {
        $rows = [];
        foreach ($shops as $shop) {
            array_push($rows, ...$this->shopRows($shop['shop'], $shop['status']));
        }

        return $rows;
    }

    /**
     * @return list<array{host: string, state: string, message: string}>
     */
    private function shopRows(ShopHosts $shop, ?StoreDomainStatus $status): array
    {
        $t = $this->translator;
        $label = implode(', ', $shop->hosts);

        if ($status === null) {
            return [[
                'host' => $label,
                'state' => HealthPanel::WARNING,
                'message' => $t->trans('Your store domain is verified automatically after you save the settings with your public key.', [], 'Modules.Axitrace.Admin'),
            ]];
        }

        if ($status->error === StoreDomainStatus::ERROR_NO_PUBLIC_KEY) {
            return [[
                'host' => $label,
                'state' => HealthPanel::WARNING,
                'message' => $t->trans('Enter the public key and save the settings: your store domain is then verified automatically.', [], 'Modules.Axitrace.Admin'),
            ]];
        }

        $rows = [];
        foreach ($status->hosts as $entry) {
            $problem = $this->hostProblem($entry);
            $rows[] = [
                'host' => $entry['host'],
                'state' => $problem === null ? HealthPanel::OK : ($entry['status'] === StoreDomainStatus::PENDING ? HealthPanel::WARNING : HealthPanel::ERROR),
                'message' => $problem ?? $t->trans('Verified: AxiTrace accepts the browser events of your store.', [], 'Modules.Axitrace.Admin'),
            ];
        }

        if ($status->error !== null) {
            $rows[] = ['host' => $label, 'state' => HealthPanel::ERROR, 'message' => $this->errorMessage($status->error)];
        }

        return $rows;
    }

    /**
     * What is wrong with one host in a sentence a merchant understands, or null when it is verified.
     *
     * @param array{host: string, status: string, reason: string|null, browser_events_rejected: bool} $entry
     */
    private function hostProblem(array $entry): ?string
    {
        $t = $this->translator;
        $host = ['%host%' => $entry['host']];

        if ($entry['status'] === StoreDomainStatus::VERIFIED) {
            return null;
        }
        if ($entry['status'] === StoreDomainStatus::PENDING) {
            return $t->trans('AxiTrace is still verifying %host%. It retries automatically within 15 minutes.', $host, 'Modules.Axitrace.Admin');
        }

        return match ((string) $entry['reason']) {
            'staging_host', 'not_public_host', 'invalid_host' => $t->trans('%host% looks like a test or local copy of your store, so AxiTrace does not accept its browser events. Your live store is verified automatically.', $host, 'Modules.Axitrace.Admin'),
            'held_by_other_workspace' => $t->trans('%host% is connected to a different AxiTrace workspace. Use the public key of that workspace here, or write to support@axitrace.com.', $host, 'Modules.Axitrace.Admin'),
            'proof_other_workspace' => $t->trans('%host% answered AxiTrace with a different public key. Make sure this shop uses the public key of your AxiTrace workspace.', $host, 'Modules.Axitrace.Admin'),
            'proof_signature_missing', 'proof_signature_invalid' => $t->trans('The secret key does not belong to the same AxiTrace workspace as the public key. Copy both keys from the same workspace.', [], 'Modules.Axitrace.Admin'),
            default => $t->trans('AxiTrace could not open the verification address of %host% (a security module, firewall or maintenance mode may block it). Allow requests to /index.php?fc=module&module=axitrace&controller=domainproof, or add the domain in AxiTrace under Settings > Tracking & domains.', $host, 'Modules.Axitrace.Admin'),
        };
    }

    private function errorMessage(string $error): string
    {
        $t = $this->translator;

        return match ($error) {
            StoreDomainStatus::ERROR_NO_STORE_HOST => $t->trans('No shop URL was found for this shop. Check Shop Parameters > Traffic & SEO > Shop URLs.', [], 'Modules.Axitrace.Admin'),
            'workspace_not_found', 'invalid_key_format' => $t->trans('AxiTrace does not recognise the public key. Copy the public key of your workspace again.', [], 'Modules.Axitrace.Admin'),
            'invalid_secret_key' => $t->trans('AxiTrace refused the secret key. Copy the secret key of your workspace again, or clear it.', [], 'Modules.Axitrace.Admin'),
            'rate_limited' => $t->trans('Too many verification attempts in the last hour. AxiTrace tries again automatically within a day, or use the button below later.', [], 'Modules.Axitrace.Admin'),
            'invalid_hosts' => $t->trans('AxiTrace refused the shop URL as a store domain. Check Shop Parameters > Traffic & SEO > Shop URLs.', [], 'Modules.Axitrace.Admin'),
            default => $t->trans('AxiTrace could not be reached to verify your store domain. It tries again automatically every day, or use the button below.', [], 'Modules.Axitrace.Admin'),
        };
    }
}
