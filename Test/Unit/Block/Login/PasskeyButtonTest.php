<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Block\Login;

use MageOS\PasskeyAuth\Block\Login\ConditionalLogin;
use MageOS\PasskeyAuth\Block\Login\PasskeyButton;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class PasskeyButtonTest extends TestCase
{
    private Context&Stub $contextMock;
    private UrlInterface&MockObject $urlBuilderMock;
    private RequestInterface&Stub $requestMock;
    private PasskeyButton $block;

    protected function setUp(): void
    {
        $this->urlBuilderMock = $this->createMock(UrlInterface::class);
        $this->contextMock = $this->createStub(Context::class);
        $this->contextMock->method('getUrlBuilder')->willReturn($this->urlBuilderMock);
        $this->requestMock = $this->createStub(RequestInterface::class);
        $this->contextMock->method('getRequest')->willReturn($this->requestMock);

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

    public function testGetVerifyUrlCarriesLoginReferer(): void
    {
        $referer = 'aHR0cHM6Ly9leGFtcGxlLmNvbS9jaGVja291dC9jYXJ0Lw~~';
        $this->requestMock->method('getParam')->willReturnMap([['referer', null, $referer]]);
        $this->urlBuilderMock->method('getUrl')
            ->with('passkey/authentication/verify', ['referer' => $referer])
            ->willReturn('https://example.com/passkey/authentication/verify/referer/' . $referer . '/');

        $this->assertSame(
            'https://example.com/passkey/authentication/verify/referer/' . $referer . '/',
            $this->block->getVerifyUrl()
        );
    }

    public function testGetVerifyUrlIgnoresMalformedReferer(): void
    {
        $this->requestMock->method('getParam')->willReturnMap([['referer', null, '"><script>']]);
        $this->urlBuilderMock->method('getUrl')
            ->with('passkey/authentication/verify', [])
            ->willReturn('https://example.com/passkey/authentication/verify/');

        $this->assertSame('https://example.com/passkey/authentication/verify/', $this->block->getVerifyUrl());
    }

    public function testConditionalLoginVerifyUrlOmitsReferer(): void
    {
        $this->requestMock->method('getParam')->willReturnMap([['referer', null, 'aHR0cHM6Ly9leGFtcGxlLmNvbS8~']]);
        $this->urlBuilderMock->method('getUrl')
            ->with('passkey/authentication/verify', [])
            ->willReturn('https://example.com/passkey/authentication/verify/');

        $block = new ConditionalLogin($this->contextMock, $this->createStub(HttpContext::class));

        $this->assertSame('https://example.com/passkey/authentication/verify/', $block->getVerifyUrl());
    }
}
