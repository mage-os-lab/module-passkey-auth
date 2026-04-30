<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Adminhtml\Passkey;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;
use Magento\TwoFactorAuth\Api\TfaInterface;
use Magento\TwoFactorAuth\Api\UserConfigManagerInterface;
use Magento\TwoFactorAuth\Controller\Adminhtml\AbstractAction;

class Auth extends AbstractAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly Session $session,
        private readonly TfaInterface $tfa,
        private readonly UserConfigManagerInterface $userConfigManager
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $providerCode = $this->getRequest()->getParam('provider', 'passkey');
        $user = $this->session->getUser();
        if ($user) {
            $this->userConfigManager->setDefaultProvider(
                (int) $user->getId(),
                $providerCode
            );
        }

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->set(__('Passkey Authentication'));
        return $page;
    }

    protected function _isAllowed(): bool
    {
        $user = $this->session->getUser();
        if (!$user) {
            return false;
        }
        $userId = (int) $user->getId();
        $providerCode = $this->getRequest()->getParam('provider', 'passkey');

        try {
            $provider = $this->tfa->getProvider($providerCode);
            return $provider !== null && $provider->isEnabled() && $provider->isActive($userId);
        } catch (\Exception $e) {
            return false;
        }
    }
}
