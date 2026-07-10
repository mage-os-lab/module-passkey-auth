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
    private Session $session;
    private TfaInterface $tfa;

    public function __construct(
        Context $context,
        Session $session,
        TfaInterface $tfa,
        HtmlAreaTokenVerifier $tokenVerifier
    ) {
        parent::__construct($context, $tokenVerifier);
        $this->session = $session;
        $this->tfa = $tfa;
    }

    public function execute(): Page
    {
        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->set(__('Passkey Configuration'));
        return $page;
    }

    protected function _isAllowed()
    {
        if (!parent::_isAllowed()) {
            return false;
        }

        $user = $this->session->getUser();
        if (!$user) {
            return false;
        }
        $userId = (int) $user->getId();
        $providerCode = $this->getProviderCode();

        try {
            $provider = $this->tfa->getProvider($providerCode);
            return $provider !== null && $provider->isEnabled() && !$provider->isActive($userId);
        } catch (\Exception $e) {
            return false;
        }
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
