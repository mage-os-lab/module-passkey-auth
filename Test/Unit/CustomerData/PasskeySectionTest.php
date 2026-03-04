<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\CustomerData;

use MageOS\PasskeyAuth\CustomerData\PasskeySection;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use PHPUnit\Framework\TestCase;

class PasskeySectionTest extends TestCase
{
    use MocksConfigTrait;
    use MocksCredentialRepositoryTrait;
    use MocksCustomerSessionTrait;

    private PasskeySection $section;

    protected function setUp(): void
    {
        $this->createConfigMock();
        $this->createCustomerSessionMock();
        $this->createCredentialRepositoryMock();

        $this->section = new PasskeySection(
            $this->configMock,
            $this->customerSessionMock,
            $this->credentialRepositoryMock
        );
    }

    public function testNotLoggedIn(): void
    {
        $this->configureNotLoggedIn();

        $result = $this->section->getSectionData();

        $this->assertSame(['show_enrollment_prompt' => false], $result);
    }

    public function testFeatureDisabled(): void
    {
        $this->configureLoggedIn(42);
        $this->configureEnabled(false);

        $result = $this->section->getSectionData();

        $this->assertSame(['show_enrollment_prompt' => false], $result);
    }

    public function testPromptDisabled(): void
    {
        $this->configureLoggedIn(42);
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(false);

        $result = $this->section->getSectionData();

        $this->assertSame(['show_enrollment_prompt' => false], $result);
    }

    public function testEnabledWithPasskeys(): void
    {
        $this->configureLoggedIn(42);
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(true);
        $this->configureCountByCustomerId(42, 2);

        $result = $this->section->getSectionData();

        $this->assertSame(['show_enrollment_prompt' => false], $result);
    }

    public function testEnabledWithoutPasskeys(): void
    {
        $this->configureLoggedIn(42);
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(true);
        $this->configureCountByCustomerId(42, 0);

        $result = $this->section->getSectionData();

        $this->assertSame(['show_enrollment_prompt' => true], $result);
    }
}
