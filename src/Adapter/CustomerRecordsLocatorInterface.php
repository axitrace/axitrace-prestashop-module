<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Adapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axitrace\PrestaShop\Gdpr\CustomerRecords;
use Axitrace\PrestaShop\Gdpr\GdprSubject;

/** Finds the orders and carts of a GDPR subject in the PrestaShop tables. */
interface CustomerRecordsLocatorInterface
{
    public function locate(GdprSubject $subject): CustomerRecords;
}
