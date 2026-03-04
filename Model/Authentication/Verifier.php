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
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
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
        private readonly LoggerInterface $logger,
        private readonly DateTime $dateTime
    ) {
    }

    public function verify(string $challengeToken, string $assertionResponseJson): AuthenticationResultInterface
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('Passkey authentication is not enabled.'));
        }

        $serializer = $this->serializerFactory->get();

        $storedOptionsJson = $this->challengeManager->consume(
            $challengeToken,
            ChallengeManager::TYPE_AUTHENTICATION
        );

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
        } catch (NoSuchEntityException $e) {
            $this->eventManager->dispatch('passkey_authentication_failure', [
                'credential_id' => $credentialIdBase64,
                'reason' => 'credential_not_found',
            ]);
            throw new LocalizedException(__('Passkey verification failed. Please try again.'), $e);
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
        } catch (\Exception $e) {
            $this->eventManager->dispatch('passkey_authentication_failure', [
                'credential_id' => $credentialIdBase64,
                'reason' => $e->getMessage(),
            ]);
            throw new LocalizedException(__('Passkey verification failed. Please try again.'), $e);
        }

        $customerId = $storedCredential->getCustomerId();

        // Check for sign count decrease (possible cloned authenticator)
        if ($updatedSource->counter > 0
            && $storedCredential->getSignCount() > 0
            && $updatedSource->counter <= $storedCredential->getSignCount()
        ) {
            $this->logger->warning('Passkey sign count decreased — possible cloned authenticator', [
                'credential_id' => $credentialIdBase64,
                'customer_id' => $customerId,
                'stored_count' => $storedCredential->getSignCount(),
                'received_count' => $updatedSource->counter,
            ]);
        }

        // Update sign count and last used — don't block auth on failure
        try {
            $storedCredential->setSignCount($updatedSource->counter);
            $storedCredential->setPublicKey($serializer->serialize($updatedSource, 'json'));
            $storedCredential->setLastUsedAt($this->dateTime->gmtDate());
            $this->credentialRepository->save($storedCredential);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update passkey credential after authentication', [
                'exception' => $e->getMessage(),
                'credential_id' => $credentialIdBase64,
            ]);
        }

        try {
            $token = $this->tokenService->createTokenForCustomer($customerId);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create token for passkey customer', [
                'exception' => $e->getMessage(),
                'customer_id' => $customerId,
            ]);
            throw new LocalizedException(__('Authentication succeeded but token creation failed.'), $e);
        }

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
