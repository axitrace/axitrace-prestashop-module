<?php

declare(strict_types=1);

namespace Axitrace\PrestaShop\Tests\Unit\Support;

use Axitrace\PrestaShop\Adapter\CustomerRecordsLocatorInterface;
use Axitrace\PrestaShop\Gdpr\CustomerRecords;
use Axitrace\PrestaShop\Gdpr\GdprSubject;

/** Customers by id, e-mail and phone, each with their orders and carts. */
final class FakeCustomerRecordsLocator implements CustomerRecordsLocatorInterface
{
    /** @var array<int, array{email: string, phone: string, records: CustomerRecords}> */
    private array $customers = [];

    /** @var list<GdprSubject> */
    public array $subjects = [];

    public ?\Throwable $failure = null;

    /**
     * @param list<int> $orderIds
     * @param list<int> $cartIds
     */
    public function add(int $idCustomer, string $email, string $phone, array $orderIds, array $cartIds): void
    {
        $this->customers[$idCustomer] = ['email' => $email, 'phone' => $phone, 'records' => new CustomerRecords($orderIds, $cartIds)];
    }

    public function locate(GdprSubject $subject): CustomerRecords
    {
        $this->subjects[] = $subject;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $orders = [];
        $carts = [];
        foreach ($this->customers as $id => $customer) {
            if ($id === $subject->customerId
                || ($subject->email !== '' && $customer['email'] === $subject->email)
                || ($subject->phone !== '' && $customer['phone'] === $subject->phone)) {
                $orders = array_merge($orders, $customer['records']->orderIds);
                $carts = array_merge($carts, $customer['records']->cartIds);
            }
        }

        return new CustomerRecords($orders, $carts);
    }
}
