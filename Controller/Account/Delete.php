<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Account;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;

class Delete implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly CredentialManagementInterface $credentialManagement
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
            $entityId = (int) $this->request->getParam('entity_id');
            $customerId = (int) $this->customerSession->getCustomerId();

            $this->credentialManagement->deleteCredential($customerId, $entityId);

            return $resultJson->setData([
                'errors' => false,
                'message' => __('Passkey deleted.'),
            ]);
        } catch (\Exception $e) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
