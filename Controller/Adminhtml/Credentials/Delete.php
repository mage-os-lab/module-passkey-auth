<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Controller\Adminhtml\Credentials;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Model\CustomerSignOut;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_PasskeyAuth::credentials_delete';

    public function __construct(
        Context $context,
        private readonly CredentialRepositoryInterface $credentialRepository,
        private readonly CredentialManagementInterface $credentialManagement,
        private readonly LoggerInterface $logger,
        private readonly CustomerSignOut $customerSignOut
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('*/*/index');

        $entityId = (int) $this->getRequest()->getParam('entity_id');
        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('No passkey specified.'));
            return $resultRedirect;
        }

        try {
            $credential = $this->credentialRepository->getById($entityId);
            $this->credentialManagement->revokeCredential($credential);
            $this->messageManager->addSuccessMessage(__('The passkey has been revoked.'));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This passkey no longer exists.'));
            return $resultRedirect;
        } catch (\Exception $e) {
            $this->logger->error('Admin passkey revoke failed', [
                'exception' => $e->getMessage(),
                'entity_id' => $entityId,
            ]);
            $this->messageManager->addErrorMessage(__('Unable to revoke the passkey. Please try again.'));
            return $resultRedirect;
        }

        // A lost or stolen device may still hold a session or token from this passkey
        if (!$this->customerSignOut->signOutEverywhere($credential->getCustomerId())) {
            $this->messageManager->addWarningMessage(
                __('The customer could not be signed out of their sessions and apps. See the error log.')
            );
        }

        return $resultRedirect;
    }
}
