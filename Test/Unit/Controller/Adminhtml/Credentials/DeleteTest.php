<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Adminhtml\Credentials;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Controller\Adminhtml\Credentials\Delete;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class DeleteTest extends TestCase
{
    use MocksCredentialRepositoryTrait;
    use MocksLoggerTrait;

    private const ENTITY_ID = 55;

    private CredentialManagementInterface&MockObject $credentialManagement;
    private MessageManager&MockObject $messageManager;
    private CredentialInterface&Stub $credential;
    private ?Delete $controller = null;

    protected function setUp(): void
    {
        $this->createLoggerStub();
        $this->createCredentialRepositoryStub();
        $this->credential = $this->createStub(CredentialInterface::class);
        $this->credentialManagement = $this->createMock(CredentialManagementInterface::class);
        $this->messageManager = $this->createMock(MessageManager::class);
    }

    private function controller(): Delete
    {
        if ($this->controller === null) {
            $request = $this->createStub(RequestInterface::class);
            $request->method('getParam')->willReturn((string) self::ENTITY_ID);
            $resultFactory = $this->createStub(ResultFactory::class);
            $resultFactory->method('create')->willReturn($this->createStub(Redirect::class));

            $context = $this->createStub(Context::class);
            $context->method('getRequest')->willReturn($request);
            $context->method('getResultFactory')->willReturn($resultFactory);
            $context->method('getMessageManager')->willReturn($this->messageManager);

            $this->controller = new Delete(
                $context,
                $this->credentialRepositoryMock,
                $this->credentialManagement,
                $this->loggerMock
            );
        }

        return $this->controller;
    }

    public function testRevokesPasskey(): void
    {
        $this->credentialRepositoryMock->method('getById')->willReturn($this->credential);
        $this->credentialManagement->expects($this->once())
            ->method('revokeCredential')
            ->with($this->credential)
            ->willReturn(true);
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with(__('The passkey has been revoked.'));
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $this->controller()->execute();
    }

    public function testWarnsWhenCustomerCannotBeSignedOut(): void
    {
        $this->credentialRepositoryMock->method('getById')->willReturn($this->credential);
        $this->credentialManagement->expects($this->once())->method('revokeCredential')->willReturn(false);
        // The passkey is still revoked
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with(__('The passkey has been revoked.'));
        $this->messageManager->expects($this->once())
            ->method('addWarningMessage')
            ->with(__('The customer could not be signed out of their sessions and apps. See the error log.'));

        $this->controller()->execute();
    }

    public function testShowsErrorWhenRevokeFails(): void
    {
        $this->credentialRepositoryMock->method('getById')->willReturn($this->credential);
        $this->credentialManagement->expects($this->once())
            ->method('revokeCredential')
            ->willThrowException(new \RuntimeException('Deadlock'));
        $this->messageManager->expects($this->never())->method('addSuccessMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with(__('Unable to revoke the passkey. Please try again.'));

        $this->controller()->execute();
    }

    public function testShowsErrorForMissingPasskey(): void
    {
        $this->credentialRepositoryMock->method('getById')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));
        $this->credentialManagement->expects($this->never())->method('revokeCredential');
        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with(__('This passkey no longer exists.'));

        $this->controller()->execute();
    }
}
