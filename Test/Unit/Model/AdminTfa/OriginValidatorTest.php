<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\AdminTfa;

use MageOS\PasskeyAuth\Model\AdminTfa\AdminTfaConfig;
use MageOS\PasskeyAuth\Model\AdminTfa\OriginValidator;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OriginValidatorTest extends TestCase
{
    private AdminTfaConfig&MockObject $adminTfaConfig;
    private OriginValidator $validator;

    protected function setUp(): void
    {
        $this->adminTfaConfig = $this->createMock(AdminTfaConfig::class);
        $this->validator = new OriginValidator($this->adminTfaConfig);
    }

    public function testValidatePassesWhenRpIdMatches(): void
    {
        $this->adminTfaConfig->method('getRpId')->willReturn('admin.example.com');
        $config = ['rp_id' => 'admin.example.com'];
        $this->validator->validate($config);
        $this->addToAssertionCount(1);
    }

    public function testValidateThrowsWhenRpIdMismatches(): void
    {
        $this->adminTfaConfig->method('getRpId')->willReturn('new-admin.example.com');
        $config = ['rp_id' => 'old-admin.example.com'];
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('admin domain has changed');
        $this->validator->validate($config);
    }

    public function testValidatePassesWhenNoRpIdStored(): void
    {
        $this->adminTfaConfig->method('getRpId')->willReturn('admin.example.com');
        $config = [];
        $this->validator->validate($config);
        $this->addToAssertionCount(1);
    }
}
