<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Registration;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterfaceFactory;
use MageOS\PasskeyAuth\Api\RegistrationVerifierInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\Config;
use MageOS\PasskeyAuth\Model\PasskeyEvents;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class Verifier implements RegistrationVerifierInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Ceremony $ceremony,
        private readonly CredentialRepositoryInterface $credentialRepository,
        private readonly CredentialInterfaceFactory $credentialFactory,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger,
        private readonly AdminImpersonationGuard $adminImpersonationGuard
    ) {
    }

    public function verify(
        int $customerId,
        string $challengeToken,
        string $attestationResponseJson,
        ?string $friendlyName = null
    ): CredentialInterface {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('Passkey authentication is not enabled.'));
        }

        $this->adminImpersonationGuard->assertNotImpersonated();

        if ($friendlyName !== null) {
            $friendlyName = trim($friendlyName);
            if ($friendlyName === '') {
                $friendlyName = null;
            } elseif (mb_strlen($friendlyName) > 255 || preg_match('/[<>&]/', $friendlyName)) {
                throw new LocalizedException(__('Invalid passkey name.'));
            }
        }

        try {
            $credentialSource = $this->ceremony->verifyRegistration(
                $challengeToken,
                $attestationResponseJson,
                ChallengeManager::TYPE_REGISTRATION,
                $customerId
            );
        } catch (LocalizedException $e) {
            // Challenge and response-shape errors carry their own message; not a verification failure
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('Passkey registration verification failed', [
                'reason' => $e->getMessage(),
                'customer_id' => $customerId,
            ]);
            $this->eventManager->dispatch(PasskeyEvents::REGISTRATION_FAILURE, [
                'customer_id' => $customerId,
                'reason' => PasskeyEvents::REASON_VERIFICATION_FAILED,
                'message' => $e->getMessage(),
            ]);
            throw new LocalizedException(
                __('Passkey registration verification failed. Please try again.'),
                $e instanceof \Exception ? $e : null
            );
        }

        // Re-check max credentials to prevent race condition from concurrent registrations
        $maxCredentials = $this->config->getMaxCredentials();
        if ($this->credentialRepository->countByCustomerId($customerId) >= $maxCredentials) {
            throw new LocalizedException(__('Maximum number of passkeys (%1) reached.', $maxCredentials));
        }

        $transports = $credentialSource->transports;

        /** @var CredentialInterface $credential */
        $credential = $this->credentialFactory->create();
        $credential->setCustomerId($customerId);
        $credential->setCredentialId(base64_encode($credentialSource->publicKeyCredentialId));
        $credential->setPublicKey($this->ceremony->serializeSource($credentialSource));
        $credential->setUserHandle(base64_encode($credentialSource->userHandle));
        $credential->setSignCount($credentialSource->counter);
        $credential->setTransports(!empty($transports) ? implode(',', $transports) : null);
        $credential->setFriendlyName($friendlyName);
        $credential->setAaguid($credentialSource->aaguid->toString());

        try {
            $savedCredential = $this->credentialRepository->save($credential);
        } catch (CouldNotSaveException $e) {
            // Its message can carry database detail, e.g. for a credential ID that is already registered
            $this->logger->warning('Passkey registration could not be saved', [
                'reason' => $e->getMessage(),
                'customer_id' => $customerId,
            ]);
            throw new LocalizedException(__('Passkey registration failed. Please try again.'), $e);
        }

        $this->eventManager->dispatch(PasskeyEvents::CREDENTIAL_REGISTER_AFTER, [
            'customer_id' => $customerId,
            'entity_id' => $savedCredential->getEntityId(),
            'credential_id' => $savedCredential->getCredentialId(),
            'credential' => $savedCredential,
        ]);

        return $savedCredential;
    }
}
