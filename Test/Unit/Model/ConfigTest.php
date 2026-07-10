<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private StoreManagerInterface&MockObject $storeManager;
    private MockObject $store;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->store = $this->getMockBuilder(StoreInterface::class)
            ->addMethods(['getBaseUrl'])
            ->getMockForAbstractClass();
        $this->storeManager->method('getStore')->willReturn($this->store);

        $this->config = new Config($this->scopeConfig, $this->storeManager);
    }

    public function testGetRpIdReturnsHostFromBaseUrl(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->assertSame('example.com', $this->config->getRpId());
    }

    public function testGetRpIdThrowsOnMissingHost(): void
    {
        $this->store->method('getBaseUrl')->willReturn('not-a-url');
        $this->expectException(\RuntimeException::class);
        $this->config->getRpId();
    }

    public function testGetAllowedOriginsReturnsHttpsOrigin(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://shop.example.com/');
        $this->assertSame(['https://shop.example.com'], $this->config->getAllowedOrigins());
    }

    public function testGetAllowedOriginsIncludesCustomPort(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://shop.example.com:8443/');
        $this->assertSame(['https://shop.example.com:8443'], $this->config->getAllowedOrigins());
    }

    public function testGetAllowedOriginsThrowsOnMissingScheme(): void
    {
        $this->store->method('getBaseUrl')->willReturn('//no-scheme.com');
        $this->expectException(\RuntimeException::class);
        $this->config->getAllowedOrigins();
    }

    public function testIsCredentialNotificationEnabledReadsFlag(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with(Config::XML_PATH_NOTIFY_CREDENTIAL_CHANGES, ScopeInterface::SCOPE_STORE, null)
            ->willReturn(true);
        $this->assertTrue($this->config->isCredentialNotificationEnabled());
    }

    public function testGetNotificationIdentityFallsBackToGeneral(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_NOTIFICATION_EMAIL_IDENTITY, ScopeInterface::SCOPE_STORE, null)
            ->willReturn(null);
        $this->assertSame('general', $this->config->getNotificationIdentity());
    }

    public function testGetAddedEmailTemplateFallsBackToModuleDefault(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_ADDED_EMAIL_TEMPLATE, ScopeInterface::SCOPE_STORE, null)
            ->willReturn(null);
        $this->assertSame('customer_passkey_added_email_template', $this->config->getAddedEmailTemplate());
    }

    public function testGetRemovedEmailTemplateReadsConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_REMOVED_EMAIL_TEMPLATE, ScopeInterface::SCOPE_STORE, null)
            ->willReturn('custom_template_42');
        $this->assertSame('custom_template_42', $this->config->getRemovedEmailTemplate());
    }
}
