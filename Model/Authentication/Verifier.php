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
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

class Verifier implements AuthenticationVerifierInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Ceremony $ceremony,
        private readonly CredentialRepositoryInterface $credentialRepository,
        private readonly PasskeyTokenService $tokenService,
        private readonly AuthenticationResultInterfaceFactory $resultFactory,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger,
        private readonly DateTime $dateTime,
        private readonly RateLimiter $rateLimiter,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    public function verify(string $challengeToken, string $assertionResponseJson): AuthenticationResultInterface
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('Passkey authentication is not enabled.'));
        }

        // Counted here so every entry point (storefront, REST, GraphQL) shares one limit
        $ip = $this->remoteAddress->getRemoteAddress() ?: 'unknown';
        $this->rateLimiter->checkVerifyFailRate($ip);

        try {
            return $this->verifyAssertion($challengeToken, $assertionResponseJson);
        } catch (\Exception $e) {
            $this->rateLimiter->recordVerifyFailure($ip);
            throw $e;
        }
    }

    /**
     * @throws LocalizedException
     */
    private function verifyAssertion(
        string $challengeToken,
        string $assertionResponseJson
    ): AuthenticationResultInterface {
        [$publicKeyCredential, $requestOptions] = $this->ceremony->loadAssertion(
            $challengeToken,
            $assertionResponseJson,
            ChallengeManager::TYPE_AUTHENTICATION
        );

        $credentialIdBase64 = base64_encode($publicKeyCredential->rawId);

        try {
            $storedCredential = $this->credentialRepository->getByCredentialId($credentialIdBase64);
        } catch (NoSuchEntityException $e) {
            $this->logger->warning('Passkey assertion rejected: unknown credential', [
                'credential_id' => $credentialIdBase64,
            ]);
            $this->eventManager->dispatch('passkey_authentication_failure', [
                'credential_id' => $credentialIdBase64,
                'reason' => 'credential_not_found',
            ]);
            throw new LocalizedException(__('Passkey verification failed. Please try again.'), $e);
        }

        $credentialSource = $this->ceremony->deserializeSource($storedCredential->getPublicKey());

        // Also rejects a signature counter that did not increase (possible cloned authenticator)
        try {
            $updatedSource = $this->ceremony->verifyAssertion(
                $publicKeyCredential,
                $requestOptions,
                $credentialSource
            );
        } catch (\Exception $e) {
            $this->logger->warning('Passkey assertion rejected', [
                'credential_id' => $credentialIdBase64,
                'customer_id' => $storedCredential->getCustomerId(),
                'reason' => $e->getMessage(),
            ]);
            $this->eventManager->dispatch('passkey_authentication_failure', [
                'credential_id' => $credentialIdBase64,
                'reason' => $e->getMessage(),
            ]);
            throw new LocalizedException(__('Passkey verification failed. Please try again.'), $e);
        }

        $customerId = $storedCredential->getCustomerId();

        // Update sign count and last used — don't block auth on failure
        try {
            $storedCredential->setSignCount($updatedSource->counter);
            $storedCredential->setPublicKey($this->ceremony->serializeSource($updatedSource));
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
