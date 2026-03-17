<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Block\Login;

use MageOS\PasskeyAuth\Block\Login\PasskeyButton;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PasskeyButtonTest extends TestCase
{
    private Context&MockObject $contextMock;
    private UrlInterface&MockObject $urlBuilderMock;
    private PasskeyButton $block;

    protected function setUp(): void
    {
        $this->urlBuilderMock = $this->createMock(UrlInterface::class);
        $this->contextMock = $this->createMock(Context::class);
        $this->contextMock->method('getUrlBuilder')->willReturn($this->urlBuilderMock);

        $this->block = new PasskeyButton($this->contextMock);
    }

    public function testGetOptionsUrl(): void
    {
        $this->urlBuilderMock->method('getUrl')
            ->with('passkey/authentication/options', $this->anything())
            ->willReturn('https://example.com/passkey/authentication/options/');

        $this->assertSame('https://example.com/passkey/authentication/options/', $this->block->getOptionsUrl());
    }

    public function testGetVerifyUrl(): void
    {
        $this->urlBuilderMock->method('getUrl')
            ->with('passkey/authentication/verify', $this->anything())
            ->willReturn('https://example.com/passkey/authentication/verify/');

        $this->assertSame('https://example.com/passkey/authentication/verify/', $this->block->getVerifyUrl());
    }
}
