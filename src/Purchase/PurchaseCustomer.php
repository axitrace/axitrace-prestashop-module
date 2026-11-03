<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Purchase;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** The buyer as the ad platforms match on it: customer e-mail and the invoice address. */
final class PurchaseCustomer
{
    public function __construct(
        public readonly string $email = '',
        public readonly string $phone = '',
        public readonly string $firstName = '',
        public readonly string $lastName = '',
        public readonly string $city = '',
        public readonly string $zip = '',
        public readonly string $countryIso = '',
        public readonly string $stateIso = '',
    ) {
    }
}
