<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Registration;

use MageOS\PasskeyAuth\Api\RegistrationVerifierInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

class Verify implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly RegistrationVerifierInterface $registrationVerifier,
        private readonly JsonSerializer $json,
        private readonly LoggerInterface $logger,
        private readonly ResultFactory $resultFactory
    ) {
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode(403);
        $result->setData(['errors' => true, 'message' => __('Invalid security token.')]);
        return new InvalidRequestException($result);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $request->getHeader('X-Requested-With') === 'XMLHttpRequest' ? true : null;
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
            if (!is_array($body)) {
                $body = [];
            }
            $customerId = (int) $this->customerSession->getCustomerId();

            $challengeToken = isset($body['challengeToken']) && is_string($body['challengeToken'])
                ? $body['challengeToken']
                : '';
            $credentialData = $body['credential'] ?? [];
            $friendlyName = isset($body['friendlyName']) && is_string($body['friendlyName'])
                ? $body['friendlyName']
                : null;

            $credential = $this->registrationVerifier->verify(
                $customerId,
                $challengeToken,
                $this->json->serialize($credentialData),
                $friendlyName
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
        } catch (LocalizedException $e) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Passkey registration verify error', ['exception' => $e->getMessage()]);
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => __('Passkey registration failed. Please try again.'),
            ]);
        }
    }
}
