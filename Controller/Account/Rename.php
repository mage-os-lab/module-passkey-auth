<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Account;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

class Rename implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly CredentialManagementInterface $credentialManagement,
        private readonly JsonSerializer $json
    ) {
    }

    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $resultJson->setHttpResponseCode(401)->setData([
                'errors' => true,
                'message' => __('Please sign in.'),
            ]);
        }

        try {
            $body = $this->json->unserialize($this->request->getContent());
            $entityId = (int) ($body['entity_id'] ?? 0);
            $friendlyName = (string) ($body['friendly_name'] ?? '');
            $customerId = (int) $this->customerSession->getCustomerId();

            $credential = $this->credentialManagement->renameCredential($customerId, $entityId, $friendlyName);

            return $resultJson->setData([
                'errors' => false,
                'message' => __('Passkey renamed.'),
                'friendly_name' => $credential->getFriendlyName(),
            ]);
        } catch (\Exception $e) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
