<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\ShopGroupResolverInterface;

final class FakeShopGroupResolver implements ShopGroupResolverInterface
{
    /**
     * @param array<int, int> $groups shop id => group id (default group 1)
     */
    public function __construct(private readonly array $groups = [])
    {
    }

    public function groupOf(int $idShop): int
    {
        return $this->groups[$idShop] ?? 1;
    }
}
