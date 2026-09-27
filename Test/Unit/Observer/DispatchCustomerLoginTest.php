<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Observer;

use MageOS\PasskeyAuth\Observer\DispatchCustomerLogin;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class DispatchCustomerLoginTest extends TestCase
{
    public function testDispatchesCustomerLoginWithCustomerDataObject(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->expects($this->once())->method('getById')->with(42)->willReturn($customer);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->expects($this->once())
            ->method('dispatch')
            ->with('customer_login', ['customer' => $customer]);

        (new DispatchCustomerLogin($customerRepository, $eventManager))
            ->execute($this->buildObserver(['customer_id' => 42]));
    }

    public function testSkipsWithoutCustomerId(): void
    {
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->expects($this->never())->method('getById');
        $eventManager = $this->createMock(EventManager::class);
        $eventManager->expects($this->never())->method('dispatch');

        (new DispatchCustomerLogin($customerRepository, $eventManager))->execute($this->buildObserver([]));
    }

    private function buildObserver(array $data): Observer
    {
        return new Observer(['event' => new Event($data)]);
    }
}
