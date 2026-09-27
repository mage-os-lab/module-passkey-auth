<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Account;

use MageOS\PasskeyAuth\Controller\Account\Index;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksConfigTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    use MocksConfigTrait;
    use MocksCustomerSessionTrait;

    private PageFactory&Stub $pageFactoryMock;
    private RedirectFactory&Stub $redirectFactoryMock;
    private ForwardFactory&Stub $forwardFactoryMock;
    private ?Index $controller = null;

    protected function setUp(): void
    {
        $this->pageFactoryMock = $this->createStub(PageFactory::class);
        $this->redirectFactoryMock = $this->createStub(RedirectFactory::class);
        $this->forwardFactoryMock = $this->createStub(ForwardFactory::class);
        $this->createCustomerSessionStub();
        $this->createConfigStub();
    }

    private function controller(): Index
    {
        return $this->controller ??= new Index(
            $this->pageFactoryMock,
            $this->redirectFactoryMock,
            $this->customerSessionMock,
            $this->configMock,
            $this->forwardFactoryMock
        );
    }

    public function testExecuteForwardsToNoRouteWhenDisabled(): void
    {
        $this->configureEnabled(false);

        $forwardMock = $this->createMock(Forward::class);
        $forwardMock->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();
        $this->forwardFactoryMock->method('create')->willReturn($forwardMock);
        $this->pageFactoryMock = $this->createMock(PageFactory::class);
        $this->pageFactoryMock->expects($this->never())->method('create');

        $this->assertSame($forwardMock, $this->controller()->execute());
    }

    public function testExecuteNotLoggedIn(): void
    {
        $this->configureEnabled(true);
        $this->configureNotLoggedIn();

        $redirectMock = $this->createMock(Redirect::class);

        $redirectMock->expects($this->once())
            ->method('setPath')
            ->with('customer/account/login')
            ->willReturnSelf();

        $this->redirectFactoryMock->method('create')->willReturn($redirectMock);

        $result = $this->controller()->execute();

        $this->assertSame($redirectMock, $result);
    }

    public function testExecuteLoggedIn(): void
    {
        $this->configureEnabled(true);
        $this->configureLoggedIn(42);

        $titleMock = $this->createMock(Title::class);
        $titleMock->expects($this->once())
            ->method('set')
            ->with($this->callback(function ($value) {
                return (string) $value === 'My Passkeys';
            }));

        $pageConfigMock = $this->createStub(PageConfig::class);
        $pageConfigMock->method('getTitle')->willReturn($titleMock);

        $pageMock = $this->createStub(Page::class);
        $pageMock->method('getConfig')->willReturn($pageConfigMock);

        $this->pageFactoryMock->method('create')->willReturn($pageMock);

        $result = $this->controller()->execute();

        $this->assertSame($pageMock, $result);
    }
}
