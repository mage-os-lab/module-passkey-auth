<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Credential as CredentialModel;
use MageOS\PasskeyAuth\Model\CredentialFactory as CredentialModelFactory;
use MageOS\PasskeyAuth\Model\CredentialRepository;
use MageOS\PasskeyAuth\Model\Data\Credential as CredentialDTO;
use MageOS\PasskeyAuth\Model\Data\CredentialFactory as CredentialDTOFactory;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential as CredentialResource;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential\Collection;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential\CollectionFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CredentialRepositoryTest extends TestCase
{
    private CredentialResource&MockObject $resource;
    private CredentialModelFactory&MockObject $credentialModelFactory;
    private CredentialDTOFactory&MockObject $credentialDTOFactory;
    private CollectionFactory&MockObject $collectionFactory;
    private CredentialRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(CredentialResource::class);

        $this->credentialModelFactory = $this->getMockBuilder(CredentialModelFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $this->credentialDTOFactory = $this->getMockBuilder(CredentialDTOFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $this->collectionFactory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $this->repository = new CredentialRepository(
            $this->resource,
            $this->credentialModelFactory,
            $this->credentialDTOFactory,
            $this->collectionFactory
        );
    }

    public function testGetByIdFound(): void
    {
        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $dto = new CredentialDTO();
        $this->credentialDTOFactory->method('create')->willReturn($dto);

        $this->resource->expects($this->once())
            ->method('load')
            ->with($model, 42)
            ->willReturnCallback(function (CredentialModel $m) {
                $m->setData('entity_id', 42);
                $m->setData('customer_id', 1);
                return $this->resource;
            });

        $result = $this->repository->getById(42);
        $this->assertSame($dto, $result);
        $this->assertSame(42, $result->getEntityId());
    }

    public function testGetByIdNotFound(): void
    {
        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Passkey credential with ID "99" does not exist.');
        $this->repository->getById(99);
    }

    public function testGetByCredentialIdFound(): void
    {
        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $dto = new CredentialDTO();
        $this->credentialDTOFactory->method('create')->willReturn($dto);

        $this->resource->expects($this->once())
            ->method('load')
            ->with($model, 'abc123', 'credential_id')
            ->willReturnCallback(function (CredentialModel $m) {
                $m->setData('entity_id', 10);
                $m->setData('credential_id', 'abc123');
                return $this->resource;
            });

        $result = $this->repository->getByCredentialId('abc123');
        $this->assertSame($dto, $result);
        $this->assertSame('abc123', $result->getCredentialId());
    }

    public function testGetByCredentialIdNotFound(): void
    {
        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Passkey credential not found.');
        $this->repository->getByCredentialId('nonexistent');
    }

    public function testGetByCustomerIdWithResults(): void
    {
        $model1 = $this->createCredentialModel(['entity_id' => 1, 'customer_id' => 5]);
        $model2 = $this->createCredentialModel(['entity_id' => 2, 'customer_id' => 5]);

        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('customer_id', 5);
        $collection->method('setOrder')
            ->with('created_at', 'DESC');
        $collection->method('getIterator')
            ->willReturn(new \ArrayIterator([$model1, $model2]));

        $this->collectionFactory->method('create')->willReturn($collection);

        $dto1 = new CredentialDTO();
        $dto2 = new CredentialDTO();
        $this->credentialDTOFactory->method('create')
            ->willReturnOnConsecutiveCalls($dto1, $dto2);

        $results = $this->repository->getByCustomerId(5);
        $this->assertCount(2, $results);
        $this->assertSame($dto1, $results[0]);
        $this->assertSame($dto2, $results[1]);
        $this->assertSame(1, $results[0]->getEntityId());
        $this->assertSame(2, $results[1]->getEntityId());
    }

    public function testGetByCustomerIdEmpty(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter');
        $collection->method('setOrder');
        $collection->method('getIterator')
            ->willReturn(new \ArrayIterator([]));

        $this->collectionFactory->method('create')->willReturn($collection);

        $results = $this->repository->getByCustomerId(999);
        $this->assertSame([], $results);
    }

    public function testSaveNewCredential(): void
    {
        $credential = $this->createCredentialInterfaceMock(
            entityId: null,
            customerId: 1,
            credentialId: 'cred-abc',
            publicKey: 'pk-data',
            signCount: 0
        );

        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $dto = new CredentialDTO();
        $this->credentialDTOFactory->method('create')->willReturn($dto);

        $this->resource->expects($this->never())
            ->method('load');
        $this->resource->expects($this->once())
            ->method('save')
            ->with($model);

        $result = $this->repository->save($credential);
        $this->assertSame($dto, $result);
    }

    public function testSaveExistingCredential(): void
    {
        $credential = $this->createCredentialInterfaceMock(
            entityId: 1,
            customerId: 1,
            credentialId: 'cred-abc',
            publicKey: 'pk-data',
            signCount: 5
        );

        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $dto = new CredentialDTO();
        $this->credentialDTOFactory->method('create')->willReturn($dto);

        $this->resource->expects($this->once())
            ->method('load')
            ->with($model, 1)
            ->willReturnCallback(function (CredentialModel $m) {
                $m->setData('entity_id', 1);
                return $this->resource;
            });
        $this->resource->expects($this->once())
            ->method('save')
            ->with($model);

        $result = $this->repository->save($credential);
        $this->assertSame($dto, $result);
    }

    public function testSaveExistingCredentialNotFound(): void
    {
        $credential = $this->createCredentialInterfaceMock(
            entityId: 77,
            customerId: 1,
            credentialId: 'cred-abc',
            publicKey: 'pk-data',
            signCount: 0
        );

        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Passkey credential with ID "77" does not exist.');
        $this->repository->save($credential);
    }

    public function testSaveWithLastUsedAt(): void
    {
        $credential = $this->createCredentialInterfaceMock(
            entityId: null,
            customerId: 1,
            credentialId: 'cred-abc',
            publicKey: 'pk-data',
            signCount: 3,
            lastUsedAt: '2026-03-04 12:00:00'
        );

        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $dto = new CredentialDTO();
        $this->credentialDTOFactory->method('create')->willReturn($dto);

        $this->resource->expects($this->once())
            ->method('save')
            ->with($this->callback(function (CredentialModel $savedModel) {
                return $savedModel->getData('last_used_at') === '2026-03-04 12:00:00';
            }));

        $this->repository->save($credential);
    }

    public function testSaveThrowsOnMissingCustomerId(): void
    {
        $credential = $this->createCredentialInterfaceMock(
            entityId: null,
            customerId: 0,
            credentialId: 'cred-abc',
            publicKey: 'pk-data',
            signCount: 0
        );

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Invalid customer ID for passkey credential.');
        $this->repository->save($credential);
    }

    public function testSaveThrowsOnEmptyCredentialId(): void
    {
        $credential = $this->createCredentialInterfaceMock(
            entityId: null,
            customerId: 1,
            credentialId: '',
            publicKey: 'pk-data',
            signCount: 0
        );

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Credential ID cannot be empty.');
        $this->repository->save($credential);
    }

    public function testSaveThrowsOnEmptyPublicKey(): void
    {
        $credential = $this->createCredentialInterfaceMock(
            entityId: null,
            customerId: 1,
            credentialId: 'cred-abc',
            publicKey: '',
            signCount: 0
        );

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Public key cannot be empty.');
        $this->repository->save($credential);
    }

    public function testSaveThrowsOnNegativeSignCount(): void
    {
        $credential = $this->createCredentialInterfaceMock(
            entityId: null,
            customerId: 1,
            credentialId: 'cred-abc',
            publicKey: 'pk-data',
            signCount: -1
        );

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Sign count cannot be negative.');
        $this->repository->save($credential);
    }

    public function testDeleteSuccess(): void
    {
        $credential = $this->createMock(CredentialInterface::class);
        $credential->method('getEntityId')->willReturn(42);

        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $this->resource->expects($this->once())
            ->method('load')
            ->with($model, 42)
            ->willReturnCallback(function (CredentialModel $m) {
                $m->setData('entity_id', 42);
                return $this->resource;
            });
        $this->resource->expects($this->once())
            ->method('delete')
            ->with($model);

        $result = $this->repository->delete($credential);
        $this->assertTrue($result);
    }

    public function testDeleteNotFound(): void
    {
        $credential = $this->createMock(CredentialInterface::class);
        $credential->method('getEntityId')->willReturn(99);

        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $this->expectException(CouldNotDeleteException::class);
        $this->expectExceptionMessage('Passkey credential does not exist.');
        $this->repository->delete($credential);
    }

    public function testCountByCustomerId(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('customer_id', 7);
        $collection->method('getSize')->willReturn(3);

        $this->collectionFactory->method('create')->willReturn($collection);

        $this->assertSame(3, $this->repository->countByCustomerId(7));
    }

    /**
     * Create a CredentialModel mock that uses real DataObject data storage.
     *
     * Only _construct is mocked (to skip ResourceModel init). The idFieldName
     * is set to 'entity_id' to match the real resource model behavior, so
     * getId() returns the entity_id value. All other DataObject methods
     * (getData, setData, getId) work as normal.
     */
    private function createCredentialModel(array $data = []): CredentialModel&MockObject
    {
        $model = $this->getMockBuilder(CredentialModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['_construct'])
            ->getMock();

        $model->setIdFieldName('entity_id');

        foreach ($data as $key => $value) {
            $model->setData($key, $value);
        }

        return $model;
    }

    /**
     * Create a CredentialInterface mock with the given field values.
     */
    private function createCredentialInterfaceMock(
        ?int $entityId,
        int $customerId,
        string $credentialId,
        string $publicKey,
        int $signCount,
        ?string $lastUsedAt = null,
        ?string $userHandle = null,
        ?string $transports = null,
        ?string $friendlyName = null,
        ?string $aaguid = null
    ): CredentialInterface&MockObject {
        $mock = $this->createMock(CredentialInterface::class);
        $mock->method('getEntityId')->willReturn($entityId);
        $mock->method('getCustomerId')->willReturn($customerId);
        $mock->method('getCredentialId')->willReturn($credentialId);
        $mock->method('getPublicKey')->willReturn($publicKey);
        $mock->method('getSignCount')->willReturn($signCount);
        $mock->method('getLastUsedAt')->willReturn($lastUsedAt);
        $mock->method('getUserHandle')->willReturn($userHandle ?? '');
        $mock->method('getTransports')->willReturn($transports);
        $mock->method('getFriendlyName')->willReturn($friendlyName);
        $mock->method('getAaguid')->willReturn($aaguid);

        return $mock;
    }
}
