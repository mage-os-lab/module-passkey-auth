<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Registration;

use MageOS\PasskeyAuth\Api\RegistrationVerifierInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

class Verify implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly RegistrationVerifierInterface $registrationVerifier,
        private readonly JsonSerializer $json
    ) {
    }

    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $resultJson->setHttpResponseCode(401)->setData([
                'errors' => true,
                'message' => __('Please sign in to register a passkey.'),
            ]);
        }

        try {
            $body = $this->json->unserialize($this->request->getContent());
            $customerId = (int) $this->customerSession->getCustomerId();

            $credential = $this->registrationVerifier->verify(
                $customerId,
                $body['challengeToken'] ?? '',
                $this->json->serialize($body['credential'] ?? []),
                $body['friendlyName'] ?? null
            );

            return $resultJson->setData([
                'errors' => false,
                'message' => __('Passkey registered successfully.'),
                'credential' => [
                    'entity_id' => $credential->getEntityId(),
                    'friendly_name' => $credential->getFriendlyName(),
                    'created_at' => $credential->getCreatedAt(),
                ],
            ]);
        } catch (\Exception $e) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
