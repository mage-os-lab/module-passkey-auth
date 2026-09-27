<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Adminhtml\Passkey;

use MageOS\PasskeyAuth\Model\AdminTfa\Engine;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\TwoFactorAuth\Api\TfaInterface;
use Magento\TwoFactorAuth\Api\TfaSessionInterface;
use Magento\TwoFactorAuth\Controller\Adminhtml\AbstractConfigureAction;
use Magento\TwoFactorAuth\Model\AlertInterface;
use Magento\TwoFactorAuth\Model\UserConfig\HtmlAreaTokenVerifier;

/**
 * JSON endpoint: without `credential` returns creation options; with it registers the passkey and grants 2FA.
 */
class ConfigurePost extends AbstractConfigureAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        HtmlAreaTokenVerifier $tokenVerifier,
        private readonly Session $session,
        private readonly JsonFactory $jsonFactory,
        private readonly TfaSessionInterface $tfaSession,
        private readonly TfaInterface $tfa,
        private readonly Engine $engine,
        private readonly AlertInterface $alert
    ) {
        parent::__construct($context, $tokenVerifier);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $user = $this->session->getUser();
        $request = $this->getRequest();

        try {
            if (!$request->getParam('credential')) {
                return $result->setData($this->engine->getRegistrationOptions($user));
            }

            $this->engine->activate(
                $user,
                (string) $request->getParam('challenge_token'),
                (string) $request->getParam('credential')
            );
            $this->tfaSession->grantAccess();
            $this->alert->event(
                'MageOS_PasskeyAuth',
                'Passkey registered',
                AlertInterface::LEVEL_INFO,
                $user->getUserName()
            );

            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            $this->alert->event(
                'MageOS_PasskeyAuth',
                'Passkey registration failed',
                AlertInterface::LEVEL_WARNING,
                $user->getUserName(),
                $e->getMessage()
            );

            return $result->setData([
                'success' => false,
                'message' => $e instanceof LocalizedException
                    ? $e->getMessage()
                    : __('Passkey registration failed. Please try again.'),
            ]);
        }
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
