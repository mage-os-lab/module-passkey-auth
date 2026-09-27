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
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\TwoFactorAuth\Api\TfaInterface;
use Magento\TwoFactorAuth\Api\TfaSessionInterface;
use Magento\TwoFactorAuth\Controller\Adminhtml\AbstractAction;
use Magento\TwoFactorAuth\Model\AlertInterface;

/**
 * JSON endpoint: without `credential` returns request options; with it verifies the assertion and grants 2FA.
 */
class AuthPost extends AbstractAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Session $session,
        private readonly JsonFactory $jsonFactory,
        private readonly TfaSessionInterface $tfaSession,
        private readonly TfaInterface $tfa,
        private readonly Engine $engine,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly AlertInterface $alert
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $user = $this->session->getUser();
        $request = $this->getRequest();

        try {
            if (!$request->getParam('credential')) {
                return $result->setData($this->engine->getAuthenticationOptions($user));
            }

            $this->engine->verify($user, $this->dataObjectFactory->create(['data' => [
                'challenge_token' => (string) $request->getParam('challenge_token'),
                'credential' => (string) $request->getParam('credential'),
            ]]));
            $this->tfaSession->grantAccess();

            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            $this->alert->event(
                'MageOS_PasskeyAuth',
                'Passkey authentication failed',
                AlertInterface::LEVEL_WARNING,
                $user->getUserName(),
                $e->getMessage()
            );

            return $result->setData([
                'success' => false,
                'message' => $e instanceof LocalizedException
                    ? $e->getMessage()
                    : __('Passkey verification failed. Please try again.'),
            ]);
        }
    }

    protected function _isAllowed(): bool
    {
        $user = $this->session->getUser();

        return $user !== null
            && $this->tfa->getProviderIsAllowed((int) $user->getId(), Engine::CODE)
            && $this->tfa->getProvider(Engine::CODE)->isActive((int) $user->getId());
    }
}
