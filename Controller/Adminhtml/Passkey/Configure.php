<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Adminhtml\Passkey;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;
use Magento\TwoFactorAuth\Api\TfaInterface;
use Magento\TwoFactorAuth\Controller\Adminhtml\AbstractConfigureAction;
use Magento\TwoFactorAuth\Model\UserConfig\HtmlAreaTokenVerifier;

class Configure extends AbstractConfigureAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        Session $session,
        TfaInterface $tfa,
        private readonly HtmlAreaTokenVerifier $tokenVerifier
    ) {
        parent::__construct($context, $session, $tfa);
    }

    public function execute(): Page
    {
        $this->tokenVerifier->verify();

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->set(__('Passkey Configuration'));
        return $page;
    }

    protected function isAllowed(): bool
    {
        $user = $this->session->getUser();
        if (!$user) {
            return false;
        }
        $userId = (int) $user->getId();
        $providerCode = $this->getProviderCode();
        $provider = $this->tfa->getProvider($providerCode);

        return $provider->isEnabled()
            && !$provider->isActive($userId);
    }

    private function getProviderCode(): string
    {
        $code = $this->getRequest()->getParam('provider');
        if (in_array($code, ['passkey', 'passkey_hardware'], true)) {
            return $code;
        }
        return 'passkey';
    }
}
