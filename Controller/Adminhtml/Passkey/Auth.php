<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Adminhtml\Passkey;

use MageOS\PasskeyAuth\Model\AdminTfa\Engine;
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
        private readonly UserConfigManagerInterface $userConfigManager,
        private readonly TfaInterface $tfa
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $userId = (int) $this->session->getUser()->getId();
        if ($this->userConfigManager->getDefaultProvider($userId) !== Engine::CODE) {
            $this->userConfigManager->setDefaultProvider($userId, Engine::CODE);
        }

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->set(__('Passkey Authentication'));
        return $page;
    }

    protected function _isAllowed(): bool
    {
        $user = $this->session->getUser();

        return $user !== null
            && $this->tfa->getProviderIsAllowed((int) $user->getId(), Engine::CODE)
            && $this->tfa->getProvider(Engine::CODE)->isActive((int) $user->getId());
    }
}
