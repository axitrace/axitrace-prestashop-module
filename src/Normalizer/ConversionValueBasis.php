<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Normalizer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Which order amount is reported as the purchase value. Values are identical to the AxiTrace
 * Shopware plugin, so the panel and the docs describe one setting.
 */
enum ConversionValueBasis: string
{
    /** Order total as paid by the buyer, including VAT and shipping (default). */
    case GrossTotal = 'gross_total';

    /** Order total including VAT, without shipping. */
    case GrossExclShipping = 'gross_excl_shipping';

    /** Order total excluding VAT, including net shipping. */
    case NetTotal = 'net_total';

    /** Product revenue only, excluding VAT and shipping. */
    case NetExclShipping = 'net_excl_shipping';

    public static function fromConfigValue(?string $raw): self
    {
        if ($raw === null) {
            return self::GrossTotal;
        }

        return self::tryFrom(strtolower(trim($raw))) ?? self::GrossTotal;
    }
}
