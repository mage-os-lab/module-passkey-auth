<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Block;

use MageOS\PasskeyAuth\Block\EnrollmentPrompt;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Cache\StateInterface as CacheStateInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class EnrollmentPromptTest extends TestCase
{
    use MocksConfigTrait;

    private Context&Stub $contextMock;
    private EventManagerInterface&Stub $eventManagerMock;
    private ScopeConfigInterface&Stub $scopeConfigMock;

    protected function setUp(): void
    {
        $this->eventManagerMock = $this->createStub(EventManagerInterface::class);
        $this->scopeConfigMock = $this->createStub(ScopeConfigInterface::class);

        $cacheMock = $this->createStub(CacheInterface::class);
        $cacheStateMock = $this->createStub(CacheStateInterface::class);
        $cacheStateMock->method('isEnabled')->willReturn(false);

        $this->contextMock = $this->createStub(Context::class);
        $this->contextMock->method('getEventManager')->willReturn($this->eventManagerMock);
        $this->contextMock->method('getScopeConfig')->willReturn($this->scopeConfigMock);
        $this->contextMock->method('getCache')->willReturn($cacheMock);
        $this->contextMock->method('getCacheState')->willReturn($cacheStateMock);

        $this->createConfigStub();
    }

    private function createBlock(): EnrollmentPrompt&MockObject
    {
        return $this->getMockBuilder(EnrollmentPrompt::class)
            ->setConstructorArgs([
                $this->contextMock,
                $this->configMock,
            ])
            ->onlyMethods(['fetchView', 'getTemplateFile'])
            ->getMock();
    }

    public function testToHtmlReturnsEmptyWhenDisabled(): void
    {
        $block = $this->createBlock();
        $this->configureEnabled(false);
        $block->expects($this->never())->method('fetchView');

        $this->assertSame('', $block->toHtml());
    }

    public function testToHtmlReturnsEmptyWhenBothPromptsDisabled(): void
    {
        $block = $this->createBlock();
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(false);
        $this->configurePromptOnRegistration(false);
        $block->expects($this->never())->method('fetchView');

        $this->assertSame('', $block->toHtml());
    }

    public function testToHtmlRendersWhenOnlyRegistrationPromptEnabled(): void
    {
        $block = $this->createBlock();
        $block->setTemplate('MageOS_PasskeyAuth::enrollment_prompt.phtml');
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(false);
        $this->configurePromptOnRegistration(true);

        $block->method('getTemplateFile')->willReturn('template.phtml');
        $block->expects($this->once())->method('fetchView')->willReturn('<div>prompt</div>');

        $this->assertSame('<div>prompt</div>', $block->toHtml());
    }

    public function testToHtmlRendersWhenEnabled(): void
    {
        $block = $this->createBlock();
        $block->setTemplate('MageOS_PasskeyAuth::enrollment_prompt.phtml');
        $this->configureEnabled(true);
        $this->configurePromptAfterLogin(true);

        $block->method('getTemplateFile')->willReturn('template.phtml');
        $block->expects($this->once())->method('fetchView')->willReturn('<div>prompt</div>');

        $this->assertSame('<div>prompt</div>', $block->toHtml());
    }
}
