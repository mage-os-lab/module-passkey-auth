<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Block\Login;

use Magento\Framework\View\Element\Template;

class PasskeyButton extends Template
{
    public function getOptionsUrl(): string
    {
        return $this->getUrl('passkey/authentication/options');
    }

    public function getVerifyUrl(): string
    {
        return $this->getUrl('passkey/authentication/verify');
    }
}
