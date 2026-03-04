<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Account;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class Delete implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly CredentialManagementInterface $credentialManagement,
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
        } catch (LocalizedException $e) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Passkey delete error', ['exception' => $e->getMessage()]);
            return $resultJson->setHttpResponseCode(400)->setData([
                'errors' => true,
                'message' => __('Unable to delete passkey. Please try again.'),
            ]);
        }
    }
}
