<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Enrollment;

use MageOS\PasskeyAuth\Model\Enrollment\NewAccountFlag;
use Magento\Customer\Model\Session;
use PHPUnit\Framework\TestCase;

class NewAccountFlagTest extends TestCase
{
    public function testMatchesOnlyTheFlaggedCustomer(): void
    {
        $session = $this->createMock(Session::class);
        $session->method('getData')->with('passkey_new_account_id')->willReturn(42);

        $flag = new NewAccountFlag($session);

        $this->assertTrue($flag->isSetFor(42));
        $this->assertFalse($flag->isSetFor(7));
        $this->assertFalse($flag->isSetFor(0));
    }

    public function testNotSetByDefault(): void
    {
        $session = $this->createMock(Session::class);
        $session->method('getData')->willReturn(null);

        $this->assertFalse((new NewAccountFlag($session))->isSetFor(42));
    }
}
