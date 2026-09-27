<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Block\Adminhtml\Tfa;

use Magento\Backend\Block\Template;

/**
 * Passkey auth/configure screen. Injects the endpoint and success URLs into the KO component.
 *
 * Layout argument `post_route` names the JSON endpoint (tfa/passkey/authpost or tfa/passkey/configurepost).
 */
class Passkey extends Template
{
    public function getJsLayout()
    {
        $postUrl = $this->getUrl((string) $this->getData('post_route'));
        $successUrl = $this->getUrl($this->_urlBuilder->getStartupPageUrl());
        foreach (array_keys($this->jsLayout['components'] ?? []) as $name) {
            $this->jsLayout['components'][$name]['postUrl'] = $postUrl;
            $this->jsLayout['components'][$name]['successUrl'] = $successUrl;
        }
        return parent::getJsLayout();
    }
}
