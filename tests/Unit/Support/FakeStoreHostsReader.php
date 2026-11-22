<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\StoreHostsReaderInterface;
use Axitrace\PrestaShop\Domain\ShopHosts;

final class FakeStoreHostsReader implements StoreHostsReaderInterface
{
    /** @var list<ShopHosts> */
    public array $shops;

    public function __construct(ShopHosts ...$shops)
    {
        $this->shops = array_values($shops);
    }

    public function shops(): array
    {
        return $this->shops;
    }
}
