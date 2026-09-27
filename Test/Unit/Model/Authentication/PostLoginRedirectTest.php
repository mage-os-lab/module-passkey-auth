<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Authentication;

use MageOS\PasskeyAuth\Model\Authentication\PostLoginRedirect;
use Magento\Customer\Model\Account\Redirect as AccountRedirect;
use Magento\Customer\Model\Session;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Url\DecoderInterface;
use Magento\Framework\Url\HostChecker;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class PostLoginRedirectTest extends TestCase
{
    private const BASE_URL = 'https://example.com/';
    private const ACCOUNT_URL = 'https://example.com/customer/account/';
    private const DASHBOARD_URL = 'https://example.com/customer/account/index/';
    private const LOGOUT_URL = 'https://example.com/customer/account/logout/';

    private RequestInterface&Stub $request;
    private Session&Stub $session;
    private ScopeConfigInterface&Stub $scopeConfig;
    private AccountRedirect&Stub $accountRedirect;
    private RedirectInterface&Stub $redirect;
    private array $sessionData = [];
    private ?PostLoginRedirect $postLoginRedirect = null;

    protected function setUp(): void
    {
        $this->request = $this->createStub(RequestInterface::class);
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->accountRedirect = $this->createStub(AccountRedirect::class);
        $this->redirect = $this->createStub(RedirectInterface::class);

        $this->session = $this->createStub(Session::class);
        $this->configureSessionData($this->session);
    }

    private function postLoginRedirect(): PostLoginRedirect
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn(self::BASE_URL);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $customerUrl = $this->createStub(CustomerUrl::class);
        $customerUrl->method('getAccountUrl')->willReturn(self::ACCOUNT_URL);
        $customerUrl->method('getDashboardUrl')->willReturn(self::DASHBOARD_URL);
        $customerUrl->method('getLogoutUrl')->willReturn(self::LOGOUT_URL);

        $urlDecoder = $this->createStub(DecoderInterface::class);
        $urlDecoder->method('decode')->willReturnCallback('base64_decode');

        $hostChecker = $this->createStub(HostChecker::class);
        $hostChecker->method('isOwnOrigin')->willReturnCallback(
            fn (string $url) => in_array(parse_url($url, PHP_URL_HOST), [null, 'example.com'], true)
        );

        return $this->postLoginRedirect ??= new PostLoginRedirect(
            $this->request,
            $this->session,
            $this->scopeConfig,
            $storeManager,
            $customerUrl,
            $urlDecoder,
            $hostChecker,
            $this->accountRedirect,
            $this->redirect
        );
    }

    private function configureSessionData(Session&Stub $session): void
    {
        $session->method('getId')->willReturnCallback(fn () => $this->sessionData['customer_id'] ?? null);
        $session->method('getData')->willReturnCallback(function (string $key = '', bool $clear = false) {
            $value = $this->sessionData[$key] ?? null;
            if ($clear) {
                unset($this->sessionData[$key]);
            }
            return $value;
        });
    }

    private function configureRedirectToDashboard(bool $enabled): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->willReturnCallback(fn (string $path) => $path === 'customer/startup/redirect_dashboard' && $enabled);
    }

    public function testDefaultsToAccountPage(): void
    {
        $this->assertSame(self::ACCOUNT_URL, $this->postLoginRedirect()->getUrl());
    }

    public function testRedirectCookieWins(): void
    {
        $this->configureRedirectToDashboard(false);
        $this->sessionData['before_auth_url'] = 'https://example.com/other.html';

        $this->accountRedirect = $this->createMock(AccountRedirect::class);
        $this->accountRedirect->method('getRedirectCookie')->willReturn('https://example.com/checkout/');
        $this->accountRedirect->expects($this->once())->method('clearRedirectCookie');
        $this->redirect->method('success')->willReturnCallback(fn (string $url) => $url);

        $this->assertSame('https://example.com/checkout/', $this->postLoginRedirect()->getUrl());
    }

    public function testRedirectCookieIgnoredWhenAlwaysRedirectingToDashboard(): void
    {
        $this->configureRedirectToDashboard(true);
        $this->accountRedirect = $this->createMock(AccountRedirect::class);
        $this->accountRedirect->method('getRedirectCookie')->willReturn('https://example.com/checkout/');
        $this->accountRedirect->expects($this->never())->method('clearRedirectCookie');

        $this->assertSame(self::ACCOUNT_URL, $this->postLoginRedirect()->getUrl());
    }

    public function testRedirectCookieIsCheckedToBeInternal(): void
    {
        $this->accountRedirect->method('getRedirectCookie')->willReturn('https://evil.example/');
        // Store Redirect::success() replaces an external URL with the base URL
        $this->redirect->method('success')->willReturn(self::BASE_URL);

        $this->assertSame(self::BASE_URL, $this->postLoginRedirect()->getUrl());
    }

    public function testUsesAfterAuthUrlWhenAlwaysRedirectingToDashboard(): void
    {
        $this->configureRedirectToDashboard(true);
        $this->sessionData['after_auth_url'] = 'https://example.com/wishlist/';

        $this->assertSame('https://example.com/wishlist/', $this->postLoginRedirect()->getUrl());
        $this->assertArrayNotHasKey('after_auth_url', $this->sessionData);
    }

    public function testUsesReferer(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['referer', null, base64_encode('https://example.com/checkout/cart/')],
        ]);

        $this->assertSame('https://example.com/checkout/cart/', $this->postLoginRedirect()->getUrl());
    }

    public function testStripsLogoutSuccessFromReferer(): void
    {
        $this->request->method('getParam')
            ->willReturn(base64_encode('https://example.com/customer/account/logoutSuccess/'));

        $this->assertSame('https://example.com/customer/account/', $this->postLoginRedirect()->getUrl());
    }

    public function testRejectsForeignReferer(): void
    {
        $this->request->method('getParam')->willReturn(base64_encode('https://evil.example/phish'));

        $this->assertSame(self::ACCOUNT_URL, $this->postLoginRedirect()->getUrl());
    }

    public function testRejectsScriptReferer(): void
    {
        $this->request->method('getParam')->willReturn(base64_encode('javascript:alert(document.cookie)'));

        $this->assertSame(self::ACCOUNT_URL, $this->postLoginRedirect()->getUrl());
    }

    public function testRejectsRelativeReferer(): void
    {
        $this->request->method('getParam')->willReturn(base64_encode('//evil.example/phish'));

        $this->assertSame(self::ACCOUNT_URL, $this->postLoginRedirect()->getUrl());
    }

    public function testUsesBeforeAuthUrl(): void
    {
        $this->sessionData['before_auth_url'] = 'https://example.com/sales/order/history/';

        $this->assertSame('https://example.com/sales/order/history/', $this->postLoginRedirect()->getUrl());
        $this->assertArrayNotHasKey('before_auth_url', $this->sessionData);
    }

    public function testPrefersAfterAuthUrlOverBeforeAuthUrl(): void
    {
        $this->sessionData['before_auth_url'] = 'https://example.com/sales/order/history/';
        $this->sessionData['after_auth_url'] = 'https://example.com/review/customer/';

        $this->assertSame('https://example.com/review/customer/', $this->postLoginRedirect()->getUrl());
        $this->assertSame([], $this->sessionData);
    }

    public function testLogoutUrlGoesToDashboard(): void
    {
        $this->sessionData['before_auth_url'] = self::LOGOUT_URL;

        $this->assertSame(self::DASHBOARD_URL, $this->postLoginRedirect()->getUrl());
    }

    public function testDropsTargetLeftByAnotherCustomer(): void
    {
        $this->sessionData = [
            'customer_id' => 42,
            'last_customer_id' => 7,
            'before_auth_url' => 'https://example.com/sales/order/view/order_id/1/',
        ];

        $this->session = $this->createMock(Session::class);
        $this->configureSessionData($this->session);
        $this->session->expects($this->once())
            ->method('__call')
            ->with('setLastCustomerId', [42]);

        $this->assertSame(self::ACCOUNT_URL, $this->postLoginRedirect()->getUrl());
    }

    public function testKeepsTargetOfSameCustomer(): void
    {
        $this->sessionData = [
            'customer_id' => 42,
            'last_customer_id' => '42',
            'before_auth_url' => 'https://example.com/sales/order/view/order_id/1/',
        ];

        $this->assertSame(
            'https://example.com/sales/order/view/order_id/1/',
            $this->postLoginRedirect()->getUrl()
        );
    }
}
