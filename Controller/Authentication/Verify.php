<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Authentication;

use MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface;
use MageOS\PasskeyAuth\Model\RateLimiter;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

class Verify implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly AuthenticationVerifierInterface $authenticationVerifier,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerSession $customerSession,
        private readonly RateLimiter $rateLimiter,
        private readonly JsonSerializer $json,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory
    ) {
    }

    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $body = $this->json->unserialize($this->request->getContent());
            $ip = $this->request->getClientIp() ?? 'unknown';

            $this->rateLimiter->checkVerifyFailRate($ip);

            $result = $this->authenticationVerifier->verify(
                $body['challengeToken'] ?? '',
                $this->json->serialize($body['credential'] ?? [])
            );

            $customer = $this->customerRepository->getById($result->getCustomerId());
            $this->customerSession->setCustomerDataAsLoggedIn($customer);

            if ($this->cookieManager->getCookie('mage-cache-sessid')) {
                $metadata = $this->cookieMetadataFactory->createCookieMetadata();
                $metadata->setPath('/');
                $this->cookieManager->deleteCookie('mage-cache-sessid', $metadata);
            }

            return $resultJson->setData([
                'errors' => false,
                'message' => __('Login successful.'),
            ]);
        } catch (\Exception $e) {
            $ip = $this->request->getClientIp() ?? 'unknown';
            $this->rateLimiter->recordVerifyFailure($ip);

            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => __('Passkey verification failed. Please try again.'),
            ]);
        }
    }
}
