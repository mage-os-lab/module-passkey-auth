<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Observer;

use MageOS\PasskeyAuth\Model\Enrollment\NewAccountFlag;
use MageOS\PasskeyAuth\Observer\FlagNewAccount;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class FlagNewAccountTest extends TestCase
{
    public function testFlagsRegisteredCustomer(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $flag = $this->createMock(NewAccountFlag::class);
        $flag->expects($this->once())->method('set')->with(42);

        (new FlagNewAccount($flag))->execute($this->observerWith($customer));
    }

    public function testIgnoresMissingCustomer(): void
    {
        $flag = $this->createMock(NewAccountFlag::class);
        $flag->expects($this->never())->method('set');

        (new FlagNewAccount($flag))->execute($this->observerWith(null));
    }

    private function observerWith(?CustomerInterface $customer): Observer
    {
        return new Observer(['event' => new Event(['customer' => $customer])]);
    }
}
