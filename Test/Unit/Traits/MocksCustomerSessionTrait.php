<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use Magento\Customer\Model\Session;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

trait MocksCustomerSessionTrait
{
    private Session&Stub $customerSessionMock;
    private bool $customerSessionIsMock = false;

    private function createCustomerSessionStub(): Session&Stub
    {
        $this->customerSessionMock = $this->createStub(Session::class);
        return $this->customerSessionMock;
    }

    /**
     * Replace the stub with a mock object, for tests that set expectations. Call before the subject is built
     * and before configureLoggedIn()/configureNotLoggedIn().
     */
    private function mockCustomerSession(): Session&MockObject
    {
        if (!$this->customerSessionIsMock) {
            $this->customerSessionMock = $this->createMock(Session::class);
            $this->customerSessionIsMock = true;
        }
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
