<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Authentication;

use Magento\Customer\Model\Account\Redirect as AccountRedirect;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Url\DecoderInterface;
use Magento\Framework\Url\HostChecker;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The URL a storefront passkey sign-in continues to: where core password login (Customer LoginPost with
 * Account\Redirect) would redirect the same session.
 *
 * Account\Redirect returns a result object, not a URL, so its choice is reproduced here for the JSON response.
 */
class PostLoginRedirect
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CustomerSession $customerSession,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerUrl $customerUrl,
        private readonly DecoderInterface $urlDecoder,
        private readonly HostChecker $hostChecker,
        private readonly AccountRedirect $accountRedirect,
        private readonly RedirectInterface $redirect
    ) {
    }

    /**
     * Call once the customer is logged in; like core, it consumes the session's before/after-auth URLs.
     *
     * Stricter than core, which only sends the URL as a Location header: the storefront script navigates to it,
     * so anything but an absolute http(s) URL on one of our hosts falls back to the account page.
     */
    public function getUrl(): string
    {
        $url = $this->resolveUrl();
        $isSafe = preg_match('#^https?://#i', $url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && $this->hostChecker->isOwnOrigin($url);

        return $isSafe ? $url : $this->customerUrl->getAccountUrl();
    }

    private function resolveUrl(): string
    {
        $toDashboard = $this->scopeConfig->isSetFlag(
            CustomerUrl::XML_PATH_CUSTOMER_STARTUP_REDIRECT_TO_DASHBOARD,
            ScopeInterface::SCOPE_STORE
        );

        // LoginPost: the login_redirect cookie wins unless customers always land on the dashboard
        $cookieUrl = $this->accountRedirect->getRedirectCookie();
        if (!$toDashboard && $cookieUrl) {
            $this->accountRedirect->clearRedirectCookie();
            return (string) $this->redirect->success($cookieUrl);
        }

        // Account\Redirect::updateLastCustomerId(): a different customer than the last one drops the old target
        $session = $this->customerSession;
        $lastCustomerId = $session->getData('last_customer_id');
        if ($lastCustomerId !== null && $lastCustomerId != $session->getId()) {
            $session->getData('before_auth_url', true);
            $session->setLastCustomerId($session->getId());
        }

        // Account\Redirect::prepareRedirectUrl() and getRedirect()
        $beforeAuthUrl = (string) $session->getData('before_auth_url', true);
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();

        if ($beforeAuthUrl === '' || $beforeAuthUrl === $baseUrl) {
            if (!$toDashboard) {
                return $this->getRefererUrl() ?? $this->customerUrl->getAccountUrl();
            }
            return (string) $session->getData('after_auth_url', true) ?: $this->customerUrl->getAccountUrl();
        }

        if ($beforeAuthUrl === $this->customerUrl->getLogoutUrl()) {
            return $this->customerUrl->getDashboardUrl();
        }

        return (string) $session->getData('after_auth_url', true) ?: $beforeAuthUrl;
    }

    /**
     * Account\Redirect::processLoggedCustomer(): the login page's referer parameter, validated by getUrl().
     */
    private function getRefererUrl(): ?string
    {
        $referer = $this->request->getParam(CustomerUrl::REFERER_QUERY_PARAM_NAME);
        if (!is_string($referer) || $referer === '') {
            return null;
        }

        $referer = str_replace('logoutSuccess/', '', (string) $this->urlDecoder->decode($referer));

        return $referer !== '' ? $referer : null;
    }
}
