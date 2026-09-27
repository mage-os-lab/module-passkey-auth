<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\CustomerData;

use MageOS\PasskeyAuth\CustomerData\PasskeySection;
use MageOS\PasskeyAuth\Model\Enrollment\NewAccountFlag;
use MageOS\PasskeyAuth\Model\Registration\AdminImpersonationGuard;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class PasskeySectionTest extends TestCase
{
    use MocksConfigTrait;
    use MocksCredentialRepositoryTrait;
    use MocksCustomerSessionTrait;

    private ?PasskeySection $section = null;
    private NewAccountFlag&Stub $newAccountFlag;
    private AdminImpersonationGuard&Stub $impersonationGuard;

    protected function setUp(): void
    {
        $this->createConfigStub();
        $this->createCustomerSessionStub();
        $this->createCredentialRepositoryStub();
        $this->newAccountFlag = $this->createStub(NewAccountFlag::class);
        $this->impersonationGuard = $this->createStub(AdminImpersonationGuard::class);
    }

    private function section(): PasskeySection
    {
        return $this->section ??= new PasskeySection(
            $this->configMock,
            $this->customerSessionMock,
            $this->credentialRepositoryMock,
            $this->newAccountFlag,
            $this->impersonationGuard
        );
    }

    public function testNotLoggedIn(): void
    {
        $this->configureNotLoggedIn();

        $result = $this->section()->getSectionData();

        $this->assertSame(['show_enrollment_prompt' => false], $result);
    }

    public function testFeatureDisabled(): void
    {
        $this->configureLoggedIn(42);
        $this->configureEnabled(false);

        $result = $this->section()->getSectionData();

        $this->assertSame(['show_enrollment_prompt' => false], $result);
    }

    public function testPromptDisabled(): void
    {
        $this->configureLoggedIn(42);
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(false);

        $result = $this->section()->getSectionData();

        $this->assertSame(['show_enrollment_prompt' => false], $result);
    }

    public function testEnabledWithPasskeys(): void
    {
        $this->configureLoggedIn(42);
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(true);
        $this->configureCountByCustomerId(42, 2);

        $result = $this->section()->getSectionData();

        $this->assertSame(['show_enrollment_prompt' => false], $result);
    }

    public function testEnabledWithoutPasskeys(): void
    {
        $this->configureLoggedIn(42);
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(true);
        $this->configureCountByCustomerId(42, 0);

        $result = $this->section()->getSectionData();

        $this->assertSame(['show_enrollment_prompt' => true], $result);
    }

    public function testHiddenWhileAdminIsSignedInAsCustomer(): void
    {
        $this->configureLoggedIn(42);
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(true);
        $this->impersonationGuard->method('isImpersonated')->willReturn(true);
        $this->mockCredentialRepository()->expects($this->never())->method('countByCustomerId');

        $this->assertSame(['show_enrollment_prompt' => false], $this->section()->getSectionData());
    }

    public function testNewAccountFollowsRegistrationSettingWhenDisabled(): void
    {
        $this->configureLoggedIn(42);
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(true);
        $this->configurePromptOnRegistration(false);
        $this->newAccountFlag->method('isSetFor')->willReturn(true);
        $this->configureCountByCustomerId(42, 0);

        $this->assertSame(['show_enrollment_prompt' => false], $this->section()->getSectionData());
    }

    public function testNewAccountFollowsRegistrationSettingWhenEnabled(): void
    {
        $this->configureLoggedIn(42);
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(false);
        $this->configurePromptOnRegistration(true);
        $this->newAccountFlag->method('isSetFor')->willReturn(true);
        $this->configureCountByCustomerId(42, 0);

        $this->assertSame(['show_enrollment_prompt' => true], $this->section()->getSectionData());
    }
}
