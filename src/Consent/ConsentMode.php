<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Consent;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * When the browser SDK may load and whether server-side order events wait for consent.
 * Values and semantics are identical to the AxiTrace Shopware plugin.
 */
enum ConsentMode: string
{
    /** Load immediately, send everything (default). */
    case Off = 'off';

    /** Hold the browser SDK until a consent signal; server-side order events are always sent. */
    case Browser = 'browser';

    /** Hold the browser SDK and send server-side order events only when the shopper consented. */
    case All = 'all';

    /** Unset or unknown values fall back to {@see self::Off}: an update never starts gating by itself. */
    public static function fromConfigValue(?string $raw): self
    {
        if ($raw === null) {
            return self::Off;
        }

        return self::tryFrom(strtolower(trim($raw))) ?? self::Off;
    }

    public function requiresBrowserConsent(): bool
    {
        return $this !== self::Off;
    }

    public function requiresServerConsent(): bool
    {
        return $this === self::All;
    }
}
