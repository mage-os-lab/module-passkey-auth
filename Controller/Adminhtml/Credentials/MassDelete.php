<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Adminhtml\Credentials;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterfaceFactory;
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
        private readonly CredentialInterfaceFactory $credentialFactory,
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
        } catch (\Exception $e) {
            $this->logger->error('Admin passkey mass revoke failed', ['exception' => $e->getMessage()]);
            $this->messageManager->addErrorMessage(__('Unable to revoke the selected passkeys.'));
            return $resultRedirect;
        }

        $revoked = 0;
        $notSignedOut = 0;
        foreach ($collection as $row) {
            try {
                $signedOut = $this->credentialManagement->revokeCredential(
                    $this->credentialFactory->create(['data' => $row->getData()])
                );
                $revoked++;
                if (!$signedOut) {
                    $notSignedOut++;
                }
            } catch (\Exception $e) {
                $this->logger->error('Admin passkey mass revoke failed for credential', [
                    'exception' => $e->getMessage(),
                    'entity_id' => $row->getId(),
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

        if ($notSignedOut > 0) {
            $this->messageManager->addWarningMessage(__(
                'For %1 revoked passkey(s), the customer could not be signed out of their sessions and apps. '
                . 'See the error log.',
                $notSignedOut
            ));
        }

        return $resultRedirect;
    }
}
