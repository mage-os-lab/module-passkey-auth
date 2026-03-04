<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Block;

use MageOS\PasskeyAuth\Model\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class EnrollmentPrompt extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getPasskeysUrl(): string
    {
        return $this->getUrl('passkey/account');
    }

    protected function _toHtml(): string
    {
        if (!$this->config->isEnabled() || !$this->config->isPromptAfterLoginEnabled()) {
            return '';
        }
        return parent::_toHtml();
    }
}
