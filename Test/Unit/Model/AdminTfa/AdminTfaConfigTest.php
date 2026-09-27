<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\AdminTfa;

use MageOS\PasskeyAuth\Model\AdminTfa\AdminTfaConfig;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class AdminTfaConfigTest extends TestCase
{
    private StoreManagerInterface&Stub $storeManager;
    private ?AdminTfaConfig $config = null;

    protected function setUp(): void
    {
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
    }

    private function config(): AdminTfaConfig
    {
        return $this->config ??= new AdminTfaConfig($this->storeManager);
    }

    /**
     * Replace the store manager stub with a mock object. Call before the config is built.
     */
    private function mockStoreManager(): StoreManagerInterface&MockObject
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager = $storeManager;
        return $storeManager;
    }

    public function testGetRpIdExtractsDomainFromAdminBaseUrl(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://admin.example.com/');
        $this->mockStoreManager()->method('getStore')
            ->with(Store::ADMIN_CODE)
            ->willReturn($store);

        $this->assertSame('admin.example.com', $this->config()->getRpId());
    }

    public function testGetRpIdHandlesPortInUrl(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://admin.example.com:8443/backend/');
        $this->mockStoreManager()->method('getStore')
            ->with(Store::ADMIN_CODE)
            ->willReturn($store);

        $this->assertSame('admin.example.com', $this->config()->getRpId());
    }

    public function testGetAllowedOriginsReturnsSchemeAndHost(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://admin.example.com/');
        $this->mockStoreManager()->method('getStore')
            ->with(Store::ADMIN_CODE)
            ->willReturn($store);

        $this->assertSame(['https://admin.example.com'], $this->config()->getAllowedOrigins());
    }

    public function testGetAllowedOriginsIncludesNonStandardPort(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://admin.example.com:8443/backend/');
        $this->mockStoreManager()->method('getStore')
            ->with(Store::ADMIN_CODE)
            ->willReturn($store);

        $this->assertSame(['https://admin.example.com:8443'], $this->config()->getAllowedOrigins());
    }

    public function testGetRpNameReturnsStoreName(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getName')->willReturn('My Store Admin');
        $this->mockStoreManager()->method('getStore')
            ->with(Store::ADMIN_CODE)
            ->willReturn($store);

        $this->assertSame('My Store Admin', $this->config()->getRpName());
    }

    public function testGetRpIdThrowsWhenBaseUrlHasNoHost(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('/admin/');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->expectException(\RuntimeException::class);
        $this->config()->getRpId();
    }

    public function testAdminPolicyRequiresUserVerificationAndDiscouragesResidentKeys(): void
    {
        $this->assertSame('required', $this->config()->getUserVerification());
        $this->assertSame('discouraged', $this->config()->getResidentKeyRequirement());
        $this->assertSame(60000, $this->config()->getCeremonyTimeout());
    }

    public function testAllowsAnyAuthenticatorWithoutRequestingAttestation(): void
    {
        $this->assertNull($this->config()->getAuthenticatorAttachment());
        $this->assertSame('none', $this->config()->getAttestationConveyance());
    }
}
