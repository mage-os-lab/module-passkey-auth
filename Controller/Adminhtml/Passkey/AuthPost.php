<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Adminhtml\Passkey;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\DataObjectFactory;
use Magento\TwoFactorAuth\Api\TfaSessionInterface;
use Magento\TwoFactorAuth\Controller\Adminhtml\AbstractAction;
use Magento\TwoFactorAuth\Model\AlertInterface;
use MageOS\PasskeyAuth\Api\AdminTfa\AuthenticateInterface;
use MageOS\PasskeyAuth\Model\AdminTfa\Engine;

class AuthPost extends AbstractAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Session $session,
        private readonly JsonFactory $jsonFactory,
        private readonly TfaSessionInterface $tfaSession,
        private readonly AuthenticateInterface $authenticate,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly AlertInterface $alert
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $user = $this->session->getUser();

        try {
            $providerCode = $this->getRequest()->getParam('provider', Engine::PROVIDER_CODE_ALL);
            $credentialJson = $this->getRequest()->getParam('credential');

            if ($credentialJson) {
                // Phase 2: Verify assertion
                $request = $this->dataObjectFactory->create(['data' => [
                    'challenge_token' => $this->getRequest()->getParam('challenge_token'),
                    'credential' => $credentialJson,
                ]]);

                $this->authenticate->verifyAssertion($user, $request);
                $this->tfaSession->grantAccess();

                return $result->setData([
                    'success' => true,
                    'redirect_url' => $this->getUrl('adminhtml/dashboard'),
                ]);
            }

            // Phase 1: Get authentication options
            $authData = $this->authenticate->getAuthenticationData($user, $providerCode);

            return $result->setData($authData);
        } catch (\Exception $e) {
            $this->alert->event(
                'MageOS_PasskeyAuth',
                'Passkey authentication failed for admin user ' . $user->getUserName()
                    . ': ' . $e->getMessage(),
                AlertInterface::LEVEL_WARNING
            );

            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function _isAllowed(): bool
    {
        $user = $this->session->getUser();
        return $user !== null;
    }
}
