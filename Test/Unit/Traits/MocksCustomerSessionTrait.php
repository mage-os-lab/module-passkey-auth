<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use Magento\Customer\Model\Session;
use PHPUnit\Framework\MockObject\MockObject;

trait MocksCustomerSessionTrait
{
    private Session&MockObject $customerSessionMock;

    private function createCustomerSessionMock(): Session&MockObject
    {
        $this->customerSessionMock = $this->createMock(Session::class);
        return $this->customerSessionMock;
    }

    private function configureLoggedIn(int $customerId): void
    {
        $this->customerSessionMock->method('isLoggedIn')->willReturn(true);
        $this->customerSessionMock->method('getCustomerId')->willReturn($customerId);
    }

    private function configureNotLoggedIn(): void
    {
        $this->customerSessionMock->method('isLoggedIn')->willReturn(false);
        $this->customerSessionMock->method('getCustomerId')->willReturn(null);
    }
}
