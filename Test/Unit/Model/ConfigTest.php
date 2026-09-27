<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&Stub $scopeConfig;
    private StoreManagerInterface&Stub $storeManager;
    private Store&Stub $store;
    private ?Config $config = null;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->store = $this->createStub(Store::class);
        $this->storeManager->method('getStore')->willReturn($this->store);
    }

    private function config(): Config
    {
        return $this->config ??= new Config($this->scopeConfig, $this->storeManager);
    }

    /**
     * Replace the scope config stub with a mock object. Call before the config is built.
     */
    private function mockScopeConfig(): ScopeConfigInterface&MockObject
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig = $scopeConfig;
        return $scopeConfig;
    }

    public function testGetRpIdReturnsHostFromBaseUrl(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->assertSame('example.com', $this->config()->getRpId());
    }

    public function testGetRpIdThrowsOnMissingHost(): void
    {
        $this->store->method('getBaseUrl')->willReturn('not-a-url');
        $this->expectException(\RuntimeException::class);
        $this->config()->getRpId();
    }

    public function testGetAllowedOriginsReturnsHttpsOrigin(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://shop.example.com/');
        $this->assertSame(['https://shop.example.com'], $this->config()->getAllowedOrigins());
    }

    public function testGetAllowedOriginsIncludesCustomPort(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://shop.example.com:8443/');
        $this->assertSame(['https://shop.example.com:8443'], $this->config()->getAllowedOrigins());
    }

    public function testGetAllowedOriginsThrowsOnMissingScheme(): void
    {
        $this->store->method('getBaseUrl')->willReturn('//no-scheme.com');
        $this->expectException(\RuntimeException::class);
        $this->config()->getAllowedOrigins();
    }

    public function testIsEnabledReadsCurrentStoreScope(): void
    {
        $this->expectStoreScopedFlag(Config::XML_PATH_ENABLED);
        $this->assertTrue($this->config()->isEnabled());
    }

    public function testIsPromptAfterLoginEnabledReadsCurrentStoreScope(): void
    {
        $this->expectStoreScopedFlag(Config::XML_PATH_PROMPT_AFTER_LOGIN);
        $this->assertTrue($this->config()->isPromptAfterLoginEnabled());
    }

    public function testIsPromptOnRegistrationEnabledReadsCurrentStoreScope(): void
    {
        $this->expectStoreScopedFlag(Config::XML_PATH_PROMPT_ON_REGISTRATION);
        $this->assertTrue($this->config()->isPromptOnRegistrationEnabled());
    }

    public function testIsCredentialNotificationEnabledReadsFlag(): void
    {
        $this->mockScopeConfig()->method('isSetFlag')
            ->with(Config::XML_PATH_NOTIFY_CREDENTIAL_CHANGES, ScopeInterface::SCOPE_STORE, null)
            ->willReturn(true);
        $this->assertTrue($this->config()->isCredentialNotificationEnabled());
    }

    public function testGetNotificationIdentityReadsStoreScope(): void
    {
        $this->mockScopeConfig()->method('getValue')
            ->with(Config::XML_PATH_NOTIFICATION_EMAIL_IDENTITY, ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('support');
        $this->assertSame('support', $this->config()->getNotificationIdentity(3));
    }

    public function testGetEmailTemplateReadsGivenPath(): void
    {
        $this->mockScopeConfig()->method('getValue')
            ->with(Config::XML_PATH_REMOVED_EMAIL_TEMPLATE, ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('custom_template_42');
        $this->assertSame(
            'custom_template_42',
            $this->config()->getEmailTemplate(Config::XML_PATH_REMOVED_EMAIL_TEMPLATE, 3)
        );
    }

    private function expectStoreScopedFlag(string $path): void
    {
        $this->mockScopeConfig()->expects($this->once())
            ->method('isSetFlag')
            ->with($path, ScopeInterface::SCOPE_STORE)
            ->willReturn(true);
    }
}
