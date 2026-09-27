<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Registration;

use MageOS\PasskeyAuth\Model\Registration\AdminImpersonationGuard;
use Magento\Customer\Model\Session;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class AdminImpersonationGuardTest extends TestCase
{
    public function testAllowsOrdinarySession(): void
    {
        $session = $this->createStub(Session::class);
        $session->method('getData')->willReturn(null);

        $guard = new AdminImpersonationGuard($session);
        $guard->assertNotImpersonated();

        $this->assertFalse($guard->isImpersonated());
    }

    public function testReportsLoginAsCustomerSession(): void
    {
        $session = $this->createMock(Session::class);
        $session->expects($this->once())
            ->method('getData')
            ->with('logged_as_customer_admind_id')
            ->willReturn('3');

        $this->assertTrue((new AdminImpersonationGuard($session))->isImpersonated());
    }

    public function testRefusesLoginAsCustomerSession(): void
    {
        $session = $this->createMock(Session::class);
        $session->expects($this->once())
            ->method('getData')
            ->with('logged_as_customer_admind_id')
            ->willReturn('3');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Passkeys can\'t be added while an admin is signed in as this customer.');

        (new AdminImpersonationGuard($session))->assertNotImpersonated();
    }
}
