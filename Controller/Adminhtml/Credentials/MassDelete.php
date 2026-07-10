<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Adminhtml\Credentials;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use Psr\Log\LoggerInterface;

class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_PasskeyAuth::credentials_delete';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly CredentialManagementInterface $credentialManagement,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('*/*/index');

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $revoked = 0;

            foreach ($collection as $credential) {
                try {
                    $this->credentialManagement->deleteCredential(
                        (int) $credential->getData('customer_id'),
                        (int) $credential->getId()
                    );
                    $revoked++;
                } catch (\Exception $e) {
                    $this->logger->error('Admin passkey mass revoke failed for credential', [
                        'exception' => $e->getMessage(),
                        'entity_id' => $credential->getId(),
                    ]);
                }
            }

            if ($revoked > 0) {
                $this->messageManager->addSuccessMessage(
                    __('A total of %1 passkey(s) have been revoked.', $revoked)
                );
            } else {
                $this->messageManager->addErrorMessage(__('No passkeys were revoked.'));
            }
        } catch (\Exception $e) {
            $this->logger->error('Admin passkey mass revoke failed', ['exception' => $e->getMessage()]);
            $this->messageManager->addErrorMessage(__('Unable to revoke the selected passkeys.'));
        }

        return $resultRedirect;
    }
}
