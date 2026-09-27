<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Block\Login;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Script-only block that arms passkey autofill (conditional mediation) on
 * pages with a sign-in field outside the login page, e.g. checkout.
 * Renders nothing for logged-in customers.
 */
class ConditionalLogin extends PasskeyButton
{
    public function __construct(
        Context $context,
        private readonly HttpContext $httpContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        if ($this->httpContext->getValue(CustomerContext::CONTEXT_AUTH)) {
            return '';
        }

        return parent::_toHtml();
    }
}
