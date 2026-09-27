<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Block\Login;

use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\View\Element\Template;

class PasskeyButton extends Template
{
    public function getOptionsUrl(): string
    {
        return $this->getUrl('passkey/authentication/options');
    }

    /**
     * Carries the login page's referer, as core's login form post URL does,
     * so the verify reply's redirect_url returns the customer there.
     */
    public function getVerifyUrl(): string
    {
        return $this->getUrl('passkey/authentication/verify', $this->getVerifyUrlParams());
    }

    protected function getVerifyUrlParams(): array
    {
        $referer = $this->getRequest()->getParam(CustomerUrl::REFERER_QUERY_PARAM_NAME);

        // Encoded URL (Url\Encoder alphabet); the verify endpoint checks its host.
        if (is_string($referer) && preg_match('/^[A-Za-z0-9_~-]+$/', $referer)) {
            return [CustomerUrl::REFERER_QUERY_PARAM_NAME => $referer];
        }

        return [];
    }
}
