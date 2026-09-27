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
use Magento\TwoFactorAuth\Controller\Adminhtml\AbstractConfigureAction;
use Magento\TwoFactorAuth\Model\UserConfig\HtmlAreaTokenVerifier;

class Configure extends AbstractConfigureAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        HtmlAreaTokenVerifier $tokenVerifier,
        private readonly Session $session,
        private readonly TfaInterface $tfa
    ) {
        parent::__construct($context, $tokenVerifier);
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
        $user = $this->session->getUser();

        return parent::_isAllowed()
            && $user !== null
            && $this->tfa->getProviderIsAllowed((int) $user->getId(), Engine::CODE)
            && !$this->tfa->getProvider(Engine::CODE)->isActive((int) $user->getId());
    }
}
