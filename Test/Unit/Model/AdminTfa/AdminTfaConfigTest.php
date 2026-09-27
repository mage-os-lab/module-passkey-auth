<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\AdminTfa;

use MageOS\PasskeyAuth\Model\AdminTfa\AdminTfaConfig;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminTfaConfigTest extends TestCase
{
    private StoreManagerInterface&MockObject $storeManager;
    private AdminTfaConfig $config;

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->config = new AdminTfaConfig($this->storeManager);
    }

    public function testGetRpIdExtractsDomainFromAdminBaseUrl(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://admin.example.com/');
        $this->storeManager->method('getStore')
            ->with(Store::ADMIN_CODE)
            ->willReturn($store);

        $this->assertSame('admin.example.com', $this->config->getRpId());
    }

    public function testGetRpIdHandlesPortInUrl(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://admin.example.com:8443/backend/');
        $this->storeManager->method('getStore')
            ->with(Store::ADMIN_CODE)
            ->willReturn($store);

        $this->assertSame('admin.example.com', $this->config->getRpId());
    }

    public function testGetAllowedOriginsReturnsSchemeAndHost(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://admin.example.com/');
        $this->storeManager->method('getStore')
            ->with(Store::ADMIN_CODE)
            ->willReturn($store);

        $this->assertSame(['https://admin.example.com'], $this->config->getAllowedOrigins());
    }

    public function testGetAllowedOriginsIncludesNonStandardPort(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://admin.example.com:8443/backend/');
        $this->storeManager->method('getStore')
            ->with(Store::ADMIN_CODE)
            ->willReturn($store);

        $this->assertSame(['https://admin.example.com:8443'], $this->config->getAllowedOrigins());
    }

    public function testGetRpNameReturnsStoreName(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getName')->willReturn('My Store Admin');
        $this->storeManager->method('getStore')
            ->with(Store::ADMIN_CODE)
            ->willReturn($store);

        $this->assertSame('My Store Admin', $this->config->getRpName());
    }

    public function testGetRpIdThrowsWhenBaseUrlHasNoHost(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('/admin/');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->expectException(\RuntimeException::class);
        $this->config->getRpId();
    }

    public function testAdminPolicyRequiresUserVerificationAndDiscouragesResidentKeys(): void
    {
        $this->assertSame('required', $this->config->getUserVerification());
        $this->assertSame('discouraged', $this->config->getResidentKeyRequirement());
        $this->assertSame(60000, $this->config->getCeremonyTimeout());
    }

    public function testAllowsAnyAuthenticatorWithoutRequestingAttestation(): void
    {
        $this->assertNull($this->config->getAuthenticatorAttachment());
        $this->assertSame('none', $this->config->getAttestationConveyance());
    }
}
