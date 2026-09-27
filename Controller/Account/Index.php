<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Account;

use MageOS\PasskeyAuth\Model\Config;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly CustomerSession $customerSession,
        private readonly Config $config,
        private readonly ForwardFactory $forwardFactory
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isEnabled()) {
            return $this->forwardFactory->create()->forward('noroute');
        }

        if (!$this->customerSession->isLoggedIn()) {
            $redirect = $this->redirectFactory->create();
            $redirect->setPath('customer/account/login');
            return $redirect;
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('My Passkeys'));
        return $page;
    }
}
