<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Consent;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Every consent rule of the module, as pure functions (port of the Shopware plugin's ConsentGate).
 *
 * The browser gate lives in views/js/axitrace-storefront.js: a page cache does not key pages on
 * the consent cookie, so a server-side browser decision would be served to the next visitor.
 * {@see isGrantSignal()} is nevertheless the canonical definition of "this cookie value means
 * consent"; the storefront script and the web SDK carry byte-identical copies of DENY_VALUES
 * (tests/fixtures/sdk_deny_values.txt pins all three).
 */
final class ConsentGate
{
    public const DECISION_GRANTED = 'granted';
    public const DECISION_DENIED = 'denied';

    /** Cookie values that are present but do NOT mean consent. */
    public const DENY_VALUES = ['', '0', 'false', 'no', 'deny', 'denied', 'opt-out'];

    /** A present cookie whose value is not in the deny-list is a grant; absence is no grant. */
    public function isGrantSignal(?string $raw): bool
    {
        if ($raw === null) {
            return false;
        }

        return !in_array(strtolower(trim($raw)), self::DENY_VALUES, true);
    }

    /**
     * The decision provable from the consent cookie of one request: granted when the cookie is a
     * grant signal, denied when it is present with a deny value, null when there is no cookie (no
     * banner, no answer yet, or no cookie name configured: nothing is provable).
     */
    public function decisionFromCookie(string $cookieName, ?string $raw): ?string
    {
        if ($cookieName === '' || $raw === null) {
            return null;
        }

        return $this->isGrantSignal($raw) ? self::DECISION_GRANTED : self::DECISION_DENIED;
    }

    /**
     * May an order be sent server-side in this mode, given the decision recorded for it? A missing
     * decision is never treated as granted in mode "all" (fail-closed).
     */
    public function allowsServerTracking(ConsentMode $mode, ?string $storedDecision): bool
    {
        if (!$mode->requiresServerConsent()) {
            return true;
        }

        return $storedDecision === self::DECISION_GRANTED;
    }
}
