<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Block;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class EnrollmentPrompt extends Template
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function shouldShow(): bool
    {
        return (bool) $this->customerSession->getData('show_passkey_enrollment_prompt');
    }

    public function getPasskeysUrl(): string
    {
        return $this->getUrl('passkey/account');
    }

    protected function _toHtml(): string
    {
        if (!$this->shouldShow()) {
            return '';
        }
        $this->customerSession->unsetData('show_passkey_enrollment_prompt');
        return parent::_toHtml();
    }
}
