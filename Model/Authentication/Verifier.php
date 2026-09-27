<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface;
use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterfaceFactory;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\Config;
use MageOS\PasskeyAuth\Model\Exception\RateLimitExceededException;
use MageOS\PasskeyAuth\Model\PasskeyEvents;
use MageOS\PasskeyAuth\Model\PasskeyTokenService;
use MageOS\PasskeyAuth\Model\RateLimiter;
use MageOS\PasskeyAuth\Model\WebAuthn\Ceremony;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
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
        private readonly RemoteAddress $remoteAddress,
        private readonly Share $shareConfig,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly AccountGuard $accountGuard
    ) {
    }

    public function verify(string $challengeToken, string $assertionResponseJson): AuthenticationResultInterface
    {
        if (!$this->config->isEnabled()) {
            throw $this->rejected(new LocalizedException(__('Passkey authentication is not enabled.')));
        }

        // Counted here so every entry point (storefront, REST, GraphQL) shares one limit
        $ip = $this->remoteAddress->getRemoteAddress() ?: 'unknown';
        try {
            $this->rateLimiter->checkVerifyFailRate($ip);
        } catch (RateLimitExceededException $e) {
            throw $this->rejected($e);
        }

        try {
            return $this->verifyAssertion($challengeToken, $assertionResponseJson);
        } catch (AuthenticationException $e) {
            // Account refused after the passkey was verified: the key holder proved possession, this isn't guessing
            throw $e;
        } catch (\Throwable $e) {
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
        try {
            [$publicKeyCredential, $requestOptions] = $this->ceremony->loadAssertion(
                $challengeToken,
                $assertionResponseJson,
                ChallengeManager::TYPE_AUTHENTICATION
            );
        } catch (LocalizedException $e) {
            throw $this->rejected($e);
        }

        $credentialIdBase64 = base64_encode($publicKeyCredential->rawId);

        $storedCredential = null;
        $customer = null;
        try {
            $storedCredential = $this->credentialRepository->getByCredentialId($credentialIdBase64);
            $customer = $this->assertCurrentWebsite($storedCredential);
        } catch (NoSuchEntityException $e) {
            $this->logger->warning('Passkey assertion rejected: unknown credential', [
                'credential_id' => $credentialIdBase64,
                'reason' => $e->getMessage(),
            ]);
            $this->eventManager->dispatch(PasskeyEvents::AUTHENTICATION_FAILURE, [
                'credential_id' => $credentialIdBase64,
                // Set when the credential exists but belongs to another website
                'customer_id' => $storedCredential?->getCustomerId(),
                'reason' => PasskeyEvents::REASON_CREDENTIAL_NOT_FOUND,
                'message' => $e->getMessage(),
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
        } catch (\Throwable $e) {
            $this->logger->warning('Passkey assertion rejected', [
                'credential_id' => $credentialIdBase64,
                'customer_id' => $storedCredential->getCustomerId(),
                'reason' => $e->getMessage(),
            ]);
            $this->eventManager->dispatch(PasskeyEvents::AUTHENTICATION_FAILURE, [
                'credential_id' => $credentialIdBase64,
                'customer_id' => $storedCredential->getCustomerId(),
                'reason' => PasskeyEvents::REASON_VERIFICATION_FAILED,
                'message' => $e->getMessage(),
            ]);
            throw new LocalizedException(
                __('Passkey verification failed. Please try again.'),
                $e instanceof \Exception ? $e : null
            );
        }

        $customerId = $storedCredential->getCustomerId();

        // Only after the assertion is verified, so only the passkey holder learns the account's state
        $customer ??= $this->customerRepository->getById($customerId);
        try {
            $this->accountGuard->assertCanSignIn($customer);
        } catch (AuthenticationException $e) {
            $this->logger->warning('Passkey sign-in refused', [
                'customer_id' => $customerId,
                'reason' => $e->getMessage(),
            ]);
            throw $e;
        }

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

        try {
            $this->accountGuard->recordSignIn($customerId);
        } catch (\Exception $e) {
            $this->logger->error('Failed to reset failed sign-in count after passkey sign-in', [
                'exception' => $e->getMessage(),
                'customer_id' => $customerId,
            ]);
        }

        // customer_customer_authenticated is deliberately not dispatched: core's UpgradeCustomerPasswordObserver
        // would rehash the event's password, and a passkey sign-in has none.
        $this->eventManager->dispatch(PasskeyEvents::AUTHENTICATION_SUCCESS, [
            'customer_id' => $customerId,
            'entity_id' => $storedCredential->getEntityId(),
            'credential_id' => $credentialIdBase64,
            'credential' => $storedCredential,
        ]);

        /** @var AuthenticationResultInterface $result */
        $result = $this->resultFactory->create(['data' => [
            'customer_id' => $customerId,
            'token' => $token,
        ]]);

        return $result;
    }

    /**
     * Log a routine rejection. Logged here, not by the callers, so storefront, REST and GraphQL each log it once.
     */
    private function rejected(LocalizedException $e): LocalizedException
    {
        $this->logger->warning('Passkey authentication rejected', ['reason' => $e->getMessage()]);

        return $e;
    }

    /**
     * With per-website customer accounts, a passkey only signs in on its owner's website. The RP ID is the host,
     * so without this a discoverable passkey would work on every website sharing it.
     *
     * @return CustomerInterface|null The owner, when it had to be loaded for the check
     * @throws NoSuchEntityException When the owner belongs to another website, handled like an unknown credential
     */
    private function assertCurrentWebsite(CredentialInterface $credential): ?CustomerInterface
    {
        if (!$this->shareConfig->isWebsiteScope()) {
            return null;
        }

        $customer = $this->customerRepository->getById($credential->getCustomerId());
        if ((int) $customer->getWebsiteId() !== (int) $this->storeManager->getStore()->getWebsiteId()) {
            throw new NoSuchEntityException(__('Passkey credential belongs to another website.'));
        }

        return $customer;
    }
}
