<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;
use MageOS\PasskeyAuth\Model\Authentication\PostLoginRedirect;
use MageOS\PasskeyAuth\Model\Exception\RateLimitExceededException;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Psr\Log\LoggerInterface;

class Verify implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly AuthenticationVerifierInterface $authenticationVerifier,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerSession $customerSession,
        private readonly JsonSerializer $json,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly LoggerInterface $logger,
        private readonly PostLoginRedirect $postLoginRedirect,
        private readonly CustomerUrl $customerUrl
    ) {
    }

    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $result = $this->verify();
        } catch (RateLimitExceededException $e) {
            // Says nothing about the account, so the customer is told to wait
            return $this->error($resultJson, 429, $e->getMessage());
        } catch (AuthenticationException $e) {
            // Refused after the passkey was verified, so only the passkey holder sees why
            return $this->error($resultJson, 403, $e->getMessage());
        } catch (LocalizedException $e) {
            // Already logged by the verifier. Generic, so it does not tell whether the passkey exists
            return $this->failure($resultJson);
        } catch (\Throwable $e) {
            $this->logger->error('Passkey authentication verify error', ['exception' => $e->getMessage()]);
            return $this->failure($resultJson);
        }

        try {
            $customer = $this->customerRepository->getById($result->getCustomerId());
            $this->customerSession->setCustomerDataAsLoggedIn($customer);

            if ($this->cookieManager->getCookie('mage-cache-sessid')) {
                $metadata = $this->cookieMetadataFactory->createCookieMetadata();
                $metadata->setPath('/');
                $this->cookieManager->deleteCookie('mage-cache-sessid', $metadata);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Passkey authentication verify error', ['exception' => $e->getMessage()]);
            return $this->failure($resultJson);
        }

        return $resultJson->setData([
            'errors' => false,
            'message' => __('Login successful.'),
            'redirect_url' => $this->getRedirectUrl(),
        ]);
    }

    /**
     * @throws LocalizedException
     */
    private function verify(): AuthenticationResultInterface
    {
        $body = $this->json->unserialize($this->request->getContent());
        if (!is_array($body)) {
            $body = [];
        }
        $challengeToken = isset($body['challengeToken']) && is_string($body['challengeToken'])
            ? $body['challengeToken']
            : '';
        $credential = $body['credential'] ?? [];

        return $this->authenticationVerifier->verify($challengeToken, $this->json->serialize($credential));
    }

    /**
     * The customer is signed in by now, so a failure here must not be reported as a failed sign-in.
     */
    private function getRedirectUrl(): string
    {
        try {
            return $this->postLoginRedirect->getUrl();
        } catch (\Throwable $e) {
            $this->logger->error('Passkey sign-in redirect error', ['exception' => $e->getMessage()]);
            return $this->customerUrl->getAccountUrl();
        }
    }

    private function failure(Json $resultJson): Json
    {
        return $this->error($resultJson, 400, __('Passkey verification failed. Please try again.'));
    }

    private function error(Json $resultJson, int $status, Phrase|string $message): Json
    {
        return $resultJson->setHttpResponseCode($status)->setData([
            'errors' => true,
            'message' => $message,
        ]);
    }
}
