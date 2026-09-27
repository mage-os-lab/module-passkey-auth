<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialSearchResultsInterfaceFactory;
use MageOS\PasskeyAuth\Model\Credential as CredentialModel;
use MageOS\PasskeyAuth\Model\CredentialFactory as CredentialModelFactory;
use MageOS\PasskeyAuth\Model\CredentialRepository;
use MageOS\PasskeyAuth\Model\CredentialSearchResults;
use MageOS\PasskeyAuth\Model\Data\Credential as CredentialDTO;
use MageOS\PasskeyAuth\Model\Data\CredentialFactory as CredentialDTOFactory;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential as CredentialResource;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential\Collection;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CredentialRepositoryTest extends TestCase
{
    private CredentialResource&Stub $resource;
    private CredentialModelFactory&Stub $credentialModelFactory;
    private CredentialDTOFactory&Stub $credentialDTOFactory;
    private CollectionFactory&Stub $collectionFactory;
    private CollectionProcessorInterface&Stub $collectionProcessor;
    private CredentialSearchResultsInterfaceFactory&Stub $searchResultsFactory;
    private ?CredentialRepository $repository = null;

    protected function setUp(): void
    {
        $this->resource = $this->createStub(CredentialResource::class);
        $this->credentialModelFactory = $this->createStub(CredentialModelFactory::class);
        $this->credentialDTOFactory = $this->createStub(CredentialDTOFactory::class);
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->collectionProcessor = $this->createStub(CollectionProcessorInterface::class);
        $this->searchResultsFactory = $this->createStub(CredentialSearchResultsInterfaceFactory::class);
    }

    private function repository(): CredentialRepository
    {
        return $this->repository ??= new CredentialRepository(
            $this->resource,
            $this->credentialModelFactory,
            $this->credentialDTOFactory,
            $this->collectionFactory,
            $this->collectionProcessor,
            $this->searchResultsFactory
        );
    }

    /**
     * Replace the resource stub with a mock object, for tests that set expectations. Call before repository().
     */
    private function mockResource(): CredentialResource&MockObject
    {
        $mock = $this->createMock(CredentialResource::class);
        $this->resource = $mock;
        return $mock;
    }

    public function testGetByIdFound(): void
    {
        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $dto = new CredentialDTO();
        $this->credentialDTOFactory->method('create')->willReturn($dto);

        $resource = $this->mockResource();
        $resource->expects($this->once())
            ->method('load')
            ->with($model, 42)
            ->willReturnCallback(function (CredentialModel $m) {
                $m->setData('entity_id', 42);
                $m->setData('customer_id', 1);
                return $this->resource;
            });

        $result = $this->repository()->getById(42);
        $this->assertSame($dto, $result);
        $this->assertSame(42, $result->getEntityId());
    }

    public function testGetByIdNotFound(): void
    {
        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Passkey credential with ID "99" does not exist.');
        $this->repository()->getById(99);
    }

    public function testGetByCredentialIdFound(): void
    {
        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $dto = new CredentialDTO();
        $this->credentialDTOFactory->method('create')->willReturn($dto);

        $resource = $this->mockResource();
        $resource->expects($this->once())
            ->method('load')
            ->with($model, hash('sha256', 'abc123'), 'credential_id_hash')
            ->willReturnCallback(function (CredentialModel $m) {
                $m->setData('entity_id', 10);
                $m->setData('credential_id', 'abc123');
                return $this->resource;
            });

        $result = $this->repository()->getByCredentialId('abc123');
        $this->assertSame($dto, $result);
        $this->assertSame('abc123', $result->getCredentialId());
    }

    public function testGetByCredentialIdNotFound(): void
    {
        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Passkey credential not found.');
        $this->repository()->getByCredentialId('nonexistent');
    }

    public function testGetByCredentialIdRejectsCaseInsensitiveMatch(): void
    {
        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        // The row MySQL returned differs from the requested ID only by case
        $this->resource->method('load')->willReturnCallback(function (CredentialModel $m) {
            $m->setData('entity_id', 10);
            $m->setData('credential_id', 'ABC123');
            return $this->resource;
        });

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Passkey credential not found.');
        $this->repository()->getByCredentialId('abc123');
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

        $results = $this->repository()->getByCustomerId(5);
        $this->assertCount(2, $results);
        $this->assertSame($dto1, $results[0]);
        $this->assertSame($dto2, $results[1]);
        $this->assertSame(1, $results[0]->getEntityId());
        $this->assertSame(2, $results[1]->getEntityId());
    }

    public function testGetByCustomerIdEmpty(): void
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter');
        $collection->method('setOrder');
        $collection->method('getIterator')
            ->willReturn(new \ArrayIterator([]));

        $this->collectionFactory->method('create')->willReturn($collection);

        $results = $this->repository()->getByCustomerId(999);
        $this->assertSame([], $results);
    }

    public function testGetListAppliesSearchCriteria(): void
    {
        $searchCriteria = $this->createStub(SearchCriteriaInterface::class);
        $model1 = $this->createCredentialModel(['entity_id' => 1, 'customer_id' => 5]);
        $model2 = $this->createCredentialModel(['entity_id' => 2, 'customer_id' => 6]);

        $collection = $this->createStub(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$model1, $model2]));
        $collection->method('getSize')->willReturn(12);
        $this->collectionFactory->method('create')->willReturn($collection);

        $collectionProcessor = $this->createMock(CollectionProcessorInterface::class);
        $this->collectionProcessor = $collectionProcessor;
        $collectionProcessor->expects($this->once())
            ->method('process')
            ->with($searchCriteria, $collection);

        $this->credentialDTOFactory->method('create')
            ->willReturnOnConsecutiveCalls(new CredentialDTO(), new CredentialDTO());
        $searchResults = new CredentialSearchResults();
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        $result = $this->repository()->getList($searchCriteria);

        $this->assertSame($searchResults, $result);
        $this->assertSame($searchCriteria, $result->getSearchCriteria());
        $this->assertSame(12, $result->getTotalCount());
        $this->assertCount(2, $result->getItems());
        $this->assertContainsOnlyInstancesOf(CredentialInterface::class, $result->getItems());
        $this->assertSame(1, $result->getItems()[0]->getEntityId());
        $this->assertSame(6, $result->getItems()[1]->getCustomerId());
    }

    public function testGetListWithNoMatches(): void
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $collection->method('getSize')->willReturn(0);
        $this->collectionFactory->method('create')->willReturn($collection);
        $this->searchResultsFactory->method('create')->willReturn(new CredentialSearchResults());

        $result = $this->repository()->getList($this->createStub(SearchCriteriaInterface::class));

        $this->assertSame([], $result->getItems());
        $this->assertSame(0, $result->getTotalCount());
    }

    public function testSaveNewCredential(): void
    {
        $credential = $this->createCredentialInterfaceStub(
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

        $resource = $this->mockResource();
        $resource->expects($this->once())
            ->method('save')
            ->with($model)
            ->willReturnCallback(function (CredentialModel $m) {
                $m->setData('entity_id', 9);
                return $this->resource;
            });
        $resource->expects($this->once())
            ->method('load')
            ->with($model, 9)
            ->willReturnCallback(function (CredentialModel $m) {
                $m->setData('created_at', '2026-01-01 00:00:00');
                return $this->resource;
            });

        $result = $this->repository()->save($credential);
        $this->assertSame($dto, $result);
        $this->assertSame('2026-01-01 00:00:00', $model->getData('created_at'));
    }

    public function testSaveExistingCredential(): void
    {
        $credential = $this->createCredentialInterfaceStub(
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

        $resource = $this->mockResource();
        $resource->expects($this->once())
            ->method('load')
            ->with($model, 1)
            ->willReturnCallback(function (CredentialModel $m) {
                $m->setData('entity_id', 1);
                return $this->resource;
            });
        $resource->expects($this->once())
            ->method('save')
            ->with($model);

        $result = $this->repository()->save($credential);
        $this->assertSame($dto, $result);
    }

    public function testSaveExistingCredentialNotFound(): void
    {
        $credential = $this->createCredentialInterfaceStub(
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
        $this->repository()->save($credential);
    }

    public function testSaveWithLastUsedAt(): void
    {
        $credential = $this->createCredentialInterfaceStub(
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

        $resource = $this->mockResource();
        $resource->expects($this->once())
            ->method('save')
            ->with($this->callback(function (CredentialModel $savedModel) {
                return $savedModel->getData('last_used_at') === '2026-03-04 12:00:00';
            }));

        $this->repository()->save($credential);
    }

    public function testSaveThrowsOnMissingCustomerId(): void
    {
        $credential = $this->createCredentialInterfaceStub(
            entityId: null,
            customerId: 0,
            credentialId: 'cred-abc',
            publicKey: 'pk-data',
            signCount: 0
        );

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Invalid customer ID for passkey credential.');
        $this->repository()->save($credential);
    }

    public function testSaveThrowsOnEmptyCredentialId(): void
    {
        $credential = $this->createCredentialInterfaceStub(
            entityId: null,
            customerId: 1,
            credentialId: '',
            publicKey: 'pk-data',
            signCount: 0
        );

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Credential ID cannot be empty.');
        $this->repository()->save($credential);
    }

    public function testSaveThrowsOnEmptyPublicKey(): void
    {
        $credential = $this->createCredentialInterfaceStub(
            entityId: null,
            customerId: 1,
            credentialId: 'cred-abc',
            publicKey: '',
            signCount: 0
        );

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Public key cannot be empty.');
        $this->repository()->save($credential);
    }

    public function testSaveThrowsOnNegativeSignCount(): void
    {
        $credential = $this->createCredentialInterfaceStub(
            entityId: null,
            customerId: 1,
            credentialId: 'cred-abc',
            publicKey: 'pk-data',
            signCount: -1
        );

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Sign count cannot be negative.');
        $this->repository()->save($credential);
    }

    public function testDeleteSuccess(): void
    {
        $credential = $this->createStub(CredentialInterface::class);
        $credential->method('getEntityId')->willReturn(42);

        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $resource = $this->mockResource();
        $resource->expects($this->once())
            ->method('load')
            ->with($model, 42)
            ->willReturnCallback(function (CredentialModel $m) {
                $m->setData('entity_id', 42);
                return $this->resource;
            });
        $resource->expects($this->once())
            ->method('delete')
            ->with($model);

        $result = $this->repository()->delete($credential);
        $this->assertTrue($result);
    }

    public function testDeleteNotFound(): void
    {
        $credential = $this->createStub(CredentialInterface::class);
        $credential->method('getEntityId')->willReturn(99);

        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $this->expectException(CouldNotDeleteException::class);
        $this->expectExceptionMessage('Passkey credential does not exist.');
        $this->repository()->delete($credential);
    }

    public function testCountByCustomerId(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('customer_id', 7);
        $collection->method('getSize')->willReturn(3);

        $this->collectionFactory->method('create')->willReturn($collection);

        $this->assertSame(3, $this->repository()->countByCustomerId(7));
    }

    public function testDeleteByIdSuccess(): void
    {
        // getById creates model #1, loads it, converts to DTO
        $model1 = $this->createCredentialModel();
        // delete creates model #2, loads it, deletes it
        $model2 = $this->createCredentialModel();

        $this->credentialModelFactory->method('create')
            ->willReturnOnConsecutiveCalls($model1, $model2);

        $dto = new CredentialDTO();
        $this->credentialDTOFactory->method('create')->willReturn($dto);

        $loadCount = 0;
        $resource = $this->mockResource();
        $resource->method('load')
            ->willReturnCallback(function (CredentialModel $m, $id) use (&$loadCount) {
                $loadCount++;
                $m->setData('entity_id', 42);
                $m->setData('customer_id', 1);
                return $this->resource;
            });

        $resource->expects($this->once())
            ->method('delete')
            ->with($model2);

        $result = $this->repository()->deleteById(42);
        $this->assertTrue($result);
    }

    public function testSaveWrapsResourceException(): void
    {
        $credential = $this->createCredentialInterfaceStub(
            entityId: null,
            customerId: 1,
            credentialId: 'cred-abc',
            publicKey: 'pk-data',
            signCount: 0
        );

        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $resource = $this->mockResource();
        $resource->expects($this->once())
            ->method('save')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Could not save passkey credential: DB error');
        $this->repository()->save($credential);
    }

    public function testDeleteWrapsResourceException(): void
    {
        $credential = $this->createStub(CredentialInterface::class);
        $credential->method('getEntityId')->willReturn(42);

        $model = $this->createCredentialModel();
        $this->credentialModelFactory->method('create')->willReturn($model);

        $resource = $this->mockResource();
        $resource->expects($this->once())
            ->method('load')
            ->with($model, 42)
            ->willReturnCallback(function (CredentialModel $m) {
                $m->setData('entity_id', 42);
                return $this->resource;
            });

        $resource->expects($this->once())
            ->method('delete')
            ->willThrowException(new \RuntimeException('FK violation'));

        $this->expectException(CouldNotDeleteException::class);
        $this->expectExceptionMessage('Could not delete passkey credential: FK violation');
        $this->repository()->delete($credential);
    }

    /**
     * Create a real CredentialModel that uses DataObject data storage.
     *
     * The constructor (and so _construct/ResourceModel init) is skipped. The
     * idFieldName is set to 'entity_id' to match the real resource model
     * behavior, so getId() returns the entity_id value. All DataObject methods
     * (getData, setData, getId) work as normal.
     */
    private function createCredentialModel(array $data = []): CredentialModel
    {
        $model = (new \ReflectionClass(CredentialModel::class))->newInstanceWithoutConstructor();

        $model->setIdFieldName('entity_id');

        foreach ($data as $key => $value) {
            $model->setData($key, $value);
        }

        return $model;
    }

    /**
     * Create a CredentialInterface stub with the given field values.
     */
    private function createCredentialInterfaceStub(
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
    ): CredentialInterface&Stub {
        $mock = $this->createStub(CredentialInterface::class);
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
