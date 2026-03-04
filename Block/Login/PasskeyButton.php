<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Block\Login;

use MageOS\PasskeyAuth\Model\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class PasskeyButton extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getOptionsUrl(): string
    {
        return $this->getUrl('passkey/authentication/options');
    }

    public function getVerifyUrl(): string
    {
        return $this->getUrl('passkey/authentication/verify');
    }

    public function getUiMode(): string
    {
        return $this->config->getUiMode();
    }
}
