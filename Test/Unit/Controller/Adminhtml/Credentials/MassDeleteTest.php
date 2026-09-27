<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Controller\Adminhtml\Credentials;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterfaceFactory;
use MageOS\PasskeyAuth\Controller\Adminhtml\Credentials\MassDelete;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential\Collection;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential\CollectionFactory;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Ui\Component\MassAction\Filter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class MassDeleteTest extends TestCase
{
    use MocksLoggerTrait;

    private Filter&Stub $filter;
    private CredentialInterfaceFactory&Stub $credentialFactory;
    private CredentialManagementInterface&MockObject $credentialManagement;
    private MessageManager&MockObject $messageManager;
    private ?MassDelete $controller = null;

    protected function setUp(): void
    {
        $this->createLoggerStub();
        $this->filter = $this->createStub(Filter::class);
        $this->credentialFactory = $this->createStub(CredentialInterfaceFactory::class);
        $this->credentialFactory->method('create')->willReturnCallback(function (array $args) {
            $credential = $this->createStub(CredentialInterface::class);
            $credential->method('getEntityId')->willReturn($args['data']['entity_id']);
            return $credential;
        });
        $this->credentialManagement = $this->createMock(CredentialManagementInterface::class);
        $this->messageManager = $this->createMock(MessageManager::class);
    }

    private function controller(): MassDelete
    {
        if ($this->controller === null) {
            $resultFactory = $this->createStub(ResultFactory::class);
            $resultFactory->method('create')->willReturn($this->createStub(Redirect::class));

            $context = $this->createStub(Context::class);
            $context->method('getResultFactory')->willReturn($resultFactory);
            $context->method('getMessageManager')->willReturn($this->messageManager);

            $collectionFactory = $this->createStub(CollectionFactory::class);
            $collectionFactory->method('create')->willReturn($this->createStub(Collection::class));

            $this->controller = new MassDelete(
                $context,
                $this->filter,
                $collectionFactory,
                $this->credentialManagement,
                $this->credentialFactory,
                $this->loggerMock
            );
        }

        return $this->controller;
    }

    /**
     * @param int[] $entityIds
     */
    private function selectRows(array $entityIds): void
    {
        $items = array_map(fn (int $entityId) => new DataObject(['entity_id' => $entityId]), $entityIds);
        $collection = $this->createStub(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));
        $this->filter->method('getCollection')->willReturn($collection);
    }

    public function testRevokesEachSelectedPasskey(): void
    {
        $this->selectRows([1, 2, 3]);
        $revoked = [];
        $this->credentialManagement->expects($this->exactly(3))
            ->method('revokeCredential')
            ->willReturnCallback(function (CredentialInterface $credential) use (&$revoked) {
                $revoked[] = $credential->getEntityId();
                return true;
            });
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with(__('A total of %1 passkey(s) have been revoked.', 3));
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $this->controller()->execute();

        $this->assertSame([1, 2, 3], $revoked);
    }

    public function testCountsOnlyRevokedPasskeys(): void
    {
        $this->selectRows([1, 2]);
        $this->credentialManagement->expects($this->exactly(2))->method('revokeCredential')->willReturnCallback(
            function (CredentialInterface $credential) {
                if ($credential->getEntityId() === 1) {
                    throw new \RuntimeException('Deadlock');
                }
                return true;
            }
        );
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with(__('A total of %1 passkey(s) have been revoked.', 1));
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $this->controller()->execute();
    }

    public function testWarnsWhenCustomersCouldNotBeSignedOut(): void
    {
        $this->selectRows([1, 2, 3]);
        $this->credentialManagement->expects($this->exactly(3))
            ->method('revokeCredential')
            ->willReturnCallback(fn (CredentialInterface $credential) => $credential->getEntityId() === 2);
        // The passkeys are still revoked
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with(__('A total of %1 passkey(s) have been revoked.', 3));
        $this->messageManager->expects($this->once())
            ->method('addWarningMessage')
            ->with(__(
                'For %1 revoked passkey(s), the customer could not be signed out of their sessions and apps. '
                . 'See the error log.',
                2
            ));

        $this->controller()->execute();
    }
}
