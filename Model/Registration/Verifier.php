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
        private readonly EventManager $eventManager
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

        $serializer = $this->serializerFactory->create();

        $storedOptionsJson = $this->challengeManager->consume($challengeToken, 'registration');

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

        $credentialSource = $validator->check(
            $publicKeyCredential->response,
            $creationOptions,
            $this->config->getRpId()
        );

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
