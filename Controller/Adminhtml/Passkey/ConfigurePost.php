<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Adminhtml\Passkey;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\TwoFactorAuth\Api\TfaInterface;
use Magento\TwoFactorAuth\Api\TfaSessionInterface;
use Magento\TwoFactorAuth\Controller\Adminhtml\AbstractConfigureAction;
use Magento\TwoFactorAuth\Model\AlertInterface;
use Magento\TwoFactorAuth\Model\UserConfig\HtmlAreaTokenVerifier;
use MageOS\PasskeyAuth\Api\AdminTfa\ConfigureInterface;
use MageOS\PasskeyAuth\Model\AdminTfa\Engine;

class ConfigurePost extends AbstractConfigureAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        Session $session,
        TfaInterface $tfa,
        private readonly JsonFactory $jsonFactory,
        private readonly TfaSessionInterface $tfaSession,
        private readonly ConfigureInterface $configure,
        private readonly AlertInterface $alert,
        private readonly HtmlAreaTokenVerifier $tokenVerifier
    ) {
        parent::__construct($context, $session, $tfa);
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $user = $this->session->getUser();

        try {
            $this->tokenVerifier->verify();

            $providerCode = $this->getRequest()->getParam('provider', Engine::PROVIDER_CODE_ALL);
            $authenticatorPolicy = $providerCode === Engine::PROVIDER_CODE_HARDWARE ? 'hardware' : 'all';

            $credentialJson = $this->getRequest()->getParam('credential');

            if ($credentialJson) {
                // Phase 2: Process attestation response
                $challengeToken = $this->getRequest()->getParam('challenge_token');
                $friendlyName = $this->getRequest()->getParam('friendly_name');

                $this->configure->activate(
                    $user,
                    $challengeToken,
                    $credentialJson,
                    $providerCode,
                    $friendlyName
                );

                $this->tfaSession->grantAccess();
                $this->alert->event(
                    'MageOS_PasskeyAuth',
                    'Passkey registered for admin user ' . $user->getUserName(),
                    AlertInterface::LEVEL_INFO
                );

                return $result->setData(['success' => true]);
            }

            // Phase 1: Generate registration options
            $registrationData = $this->configure->getRegistrationData($user, $authenticatorPolicy);

            return $result->setData($registrationData);
        } catch (\Exception $e) {
            $this->alert->event(
                'MageOS_PasskeyAuth',
                'Passkey registration failed for admin user ' . $user->getUserName()
                    . ': ' . $e->getMessage(),
                AlertInterface::LEVEL_WARNING
            );

            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function isAllowed(): bool
    {
        $user = $this->session->getUser();
        return $user !== null;
    }
}
