<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface;
use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterfaceFactory;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\Config;
use MageOS\PasskeyAuth\Model\PasskeyTokenService;
use MageOS\PasskeyAuth\Model\WebAuthn\CeremonyStepManagerProvider;
use MageOS\PasskeyAuth\Model\WebAuthn\SerializerFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;

class Verifier implements AuthenticationVerifierInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ChallengeManager $challengeManager,
        private readonly SerializerFactory $serializerFactory,
        private readonly CeremonyStepManagerProvider $ceremonyProvider,
        private readonly CredentialRepositoryInterface $credentialRepository,
        private readonly PasskeyTokenService $tokenService,
        private readonly AuthenticationResultInterfaceFactory $resultFactory,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function verify(string $challengeToken, string $assertionResponseJson): AuthenticationResultInterface
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('Passkey authentication is not enabled.'));
        }

        $serializer = $this->serializerFactory->create();

        $storedOptionsJson = $this->challengeManager->consume($challengeToken, 'authentication');

        $requestOptions = $serializer->deserialize(
            $storedOptionsJson,
            PublicKeyCredentialRequestOptions::class,
            'json'
        );

        $publicKeyCredential = $serializer->deserialize(
            $assertionResponseJson,
            PublicKeyCredential::class,
            'json'
        );

        if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            throw new LocalizedException(__('Invalid assertion response.'));
        }

        $credentialIdBase64 = base64_encode($publicKeyCredential->rawId);

        try {
            $storedCredential = $this->credentialRepository->getByCredentialId($credentialIdBase64);
        } catch (\Exception $e) {
            $this->eventManager->dispatch('passkey_authentication_failure', [
                'credential_id' => $credentialIdBase64,
                'reason' => 'credential_not_found',
            ]);
            throw new LocalizedException(__('Passkey verification failed. Please try again.'));
        }

        $credentialSource = $serializer->deserialize(
            $storedCredential->getPublicKey(),
            PublicKeyCredentialSource::class,
            'json'
        );

        $requestCSM = $this->ceremonyProvider->getRequestCeremony();
        $validator = AuthenticatorAssertionResponseValidator::create($requestCSM);

        try {
            $updatedSource = $validator->check(
                $credentialSource,
                $publicKeyCredential->response,
                $requestOptions,
                $this->config->getRpId(),
                $credentialSource->userHandle
            );
        } catch (\Throwable $e) {
            $this->eventManager->dispatch('passkey_authentication_failure', [
                'credential_id' => $credentialIdBase64,
                'reason' => $e->getMessage(),
            ]);
            throw new LocalizedException(__('Passkey verification failed. Please try again.'));
        }

        $customerId = $storedCredential->getCustomerId();

        // Update sign count and last used
        $storedCredential->setSignCount($updatedSource->counter);
        $storedCredential->setPublicKey($serializer->serialize($updatedSource, 'json'));
        $storedCredential->setLastUsedAt(date('Y-m-d H:i:s'));
        $this->credentialRepository->save($storedCredential);

        $token = $this->tokenService->createTokenForCustomer($customerId);

        $this->eventManager->dispatch('passkey_authentication_success', [
            'customer_id' => $customerId,
            'credential' => $storedCredential,
        ]);

        /** @var AuthenticationResultInterface $result */
        $result = $this->resultFactory->create(['data' => [
            'customer_id' => $customerId,
            'token' => $token,
        ]]);

        return $result;
    }
}
