<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Registration;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterfaceFactory;
use MageOS\PasskeyAuth\Api\RegistrationVerifierInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\Config;
use MageOS\PasskeyAuth\Model\WebAuthn\CeremonyStepManagerProvider;
use MageOS\PasskeyAuth\Model\WebAuthn\SerializerFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

class Verifier implements RegistrationVerifierInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ChallengeManager $challengeManager,
        private readonly SerializerFactory $serializerFactory,
        private readonly CeremonyStepManagerProvider $ceremonyProvider,
        private readonly CredentialRepositoryInterface $credentialRepository,
        private readonly CredentialInterfaceFactory $credentialFactory,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger
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

        if ($friendlyName !== null) {
            $friendlyName = trim($friendlyName);
            if ($friendlyName === '') {
                $friendlyName = null;
            } elseif (mb_strlen($friendlyName) > 255 || preg_match('/[<>&]/', $friendlyName)) {
                throw new LocalizedException(__('Invalid passkey name.'));
            }
        }

        $serializer = $this->serializerFactory->get();

        $storedOptionsJson = $this->challengeManager->consume(
            $challengeToken,
            ChallengeManager::TYPE_REGISTRATION,
            $customerId
        );

        $creationOptions = $serializer->deserialize(
            $storedOptionsJson,
            PublicKeyCredentialCreationOptions::class,
            'json'
        );

        $publicKeyCredential = $serializer->deserialize(
            $attestationResponseJson,
            PublicKeyCredential::class,
            'json'
        );

        if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw new LocalizedException(__('Invalid attestation response.'));
        }

        $creationCSM = $this->ceremonyProvider->getCreationCeremony();
        $validator = AuthenticatorAttestationResponseValidator::create($creationCSM);

        try {
            $credentialSource = $validator->check(
                $publicKeyCredential->response,
                $creationOptions,
                $this->config->getRpId()
            );
        } catch (\Exception $e) {
            $this->logger->error('Passkey registration verification failed', [
                'exception' => $e->getMessage(),
                'customer_id' => $customerId,
            ]);
            $this->eventManager->dispatch('passkey_registration_failure', [
                'customer_id' => $customerId,
                'reason' => $e->getMessage(),
            ]);
            throw new LocalizedException(__('Passkey registration verification failed. Please try again.'), $e);
        }

        // Re-check max credentials to prevent race condition from concurrent registrations
        $maxCredentials = $this->config->getMaxCredentials();
        if ($this->credentialRepository->countByCustomerId($customerId) >= $maxCredentials) {
            throw new LocalizedException(__('Maximum number of passkeys (%1) reached.', $maxCredentials));
        }

        $transports = $publicKeyCredential->response->getTransports();

        /** @var CredentialInterface $credential */
        $credential = $this->credentialFactory->create();
        $credential->setCustomerId($customerId);
        $credential->setCredentialId(base64_encode($credentialSource->publicKeyCredentialId));
        $credential->setPublicKey($serializer->serialize($credentialSource, 'json'));
        $credential->setUserHandle(base64_encode($credentialSource->userHandle));
        $credential->setSignCount($credentialSource->counter);
        $credential->setTransports(!empty($transports) ? implode(',', $transports) : null);
        $credential->setFriendlyName($friendlyName);
        $credential->setAaguid($credentialSource->aaguid->toString());

        $savedCredential = $this->credentialRepository->save($credential);

        $this->eventManager->dispatch('passkey_credential_register_after', [
            'customer_id' => $customerId,
            'credential' => $savedCredential,
        ]);

        return $savedCredential;
    }
}
