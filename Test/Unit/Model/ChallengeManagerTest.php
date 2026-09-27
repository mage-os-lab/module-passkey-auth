<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Model\ChallengeFactory;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge as ChallengeResource;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge\Collection;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge\CollectionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ChallengeManagerTest extends TestCase
{
    private ChallengeFactory&Stub $challengeFactory;
    private ChallengeResource&Stub $challengeResource;
    private CollectionFactory&Stub $collectionFactory;
    private DateTime&Stub $dateTime;
    private ?ChallengeManager $manager = null;

    protected function setUp(): void
    {
        $this->challengeFactory = $this->createStub(ChallengeFactory::class);
        $this->challengeResource = $this->createStub(ChallengeResource::class);
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->dateTime = $this->createStub(DateTime::class);
    }

    private function manager(): ChallengeManager
    {
        return $this->manager ??= new ChallengeManager(
            $this->challengeFactory,
            $this->challengeResource,
            $this->collectionFactory,
            $this->dateTime
        );
    }

    /**
     * Replace the resource stub with a mock object, for tests that set expectations. Call before manager().
     */
    private function mockChallengeResource(): ChallengeResource&MockObject
    {
        $mock = $this->createMock(ChallengeResource::class);
        $this->challengeResource = $mock;
        return $mock;
    }

    private function createModelMock(): AbstractModel&MockObject
    {
        return $this->getMockBuilder(AbstractModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setData', 'getId', 'getData'])
            ->getMock();
    }

    private function createConsumeModel(array $data): AbstractModel&Stub
    {
        $model = $this->createStub(AbstractModel::class);

        $model->method('getId')->willReturn($data['id'] ?? null);
        $model->method('getData')->willReturnCallback(
            function (?string $key = null) use ($data) {
                if ($key === null) {
                    return $data;
                }
                return $data[$key] ?? null;
            }
        );

        return $model;
    }

    private function createCollectionWithModel(AbstractModel&Stub $model): void
    {
        $collection = $this->createStub(Collection::class);
        $this->collectionFactory->method('create')->willReturn($collection);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($model);
    }

    public function testCreateReturnsChallengeToken(): void
    {
        $model = $this->createModelMock();

        $challengeFactory = $this->createMock(ChallengeFactory::class);
        $this->challengeFactory = $challengeFactory;
        $challengeFactory->expects($this->once())
            ->method('create')
            ->willReturn($model);

        $model->expects($this->once())->method('setData');

        $this->mockChallengeResource()->expects($this->once())
            ->method('save')
            ->with($model);

        $token = $this->manager()->create('registration', '{"challenge":"abc"}', 42);

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testCreateSetsAllFields(): void
    {
        $model = $this->createModelMock();
        $capturedData = [];

        $this->challengeFactory->method('create')->willReturn($model);

        $model->expects($this->once())
            ->method('setData')
            ->willReturnCallback(function (array $data) use (&$capturedData, $model) {
                $capturedData = $data;
                return $model;
            });

        $this->manager()->create('registration', '{"challenge":"data"}', 99);

        $this->assertSame('registration', $capturedData['type']);
        $this->assertSame('{"challenge":"data"}', $capturedData['challenge_data']);
        $this->assertSame(99, $capturedData['customer_id']);
        $this->assertArrayHasKey('token', $capturedData);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $capturedData['token']);
    }

    public function testCreateWithNullCustomerId(): void
    {
        $model = $this->createModelMock();
        $capturedData = [];

        $this->challengeFactory->method('create')->willReturn($model);

        $model->expects($this->once())
            ->method('setData')
            ->willReturnCallback(function (array $data) use (&$capturedData, $model) {
                $capturedData = $data;
                return $model;
            });

        $this->manager()->create('authentication', '{"challenge":"x"}');

        $this->assertNull($capturedData['customer_id']);
    }

    public function testConsumeReturnsData(): void
    {
        $createdAt = date('Y-m-d H:i:s', 1000000);
        $model = $this->createConsumeModel([
            'id' => 1,
            'type' => 'registration',
            'challenge_data' => '{"challenge":"payload"}',
            'customer_id' => '42',
            'created_at' => $createdAt,
        ]);

        $collection = $this->createMock(Collection::class);
        $this->collectionFactory->method('create')->willReturn($collection);
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('token', 'abc123')
            ->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($model);

        $this->dateTime->method('gmtTimestamp')->willReturn(1000100);

        $this->mockChallengeResource()->expects($this->once())
            ->method('delete')
            ->with($model);

        $result = $this->manager()->consume('abc123', 'registration', 42);

        $this->assertSame('{"challenge":"payload"}', $result);
    }

    public function testConsumeThrowsOnTokenNotFound(): void
    {
        $model = $this->createConsumeModel([]);

        $this->createCollectionWithModel($model);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid or expired challenge token.');

        $this->manager()->consume('nonexistent', 'registration');
    }

    public function testConsumeThrowsOnTypeMismatch(): void
    {
        $createdAt = date('Y-m-d H:i:s', 1000000);
        $model = $this->createConsumeModel([
            'id' => 1,
            'type' => 'authentication',
            'challenge_data' => '{"data":"x"}',
            'created_at' => $createdAt,
        ]);

        $this->createCollectionWithModel($model);

        $this->mockChallengeResource()->expects($this->once())
            ->method('delete')
            ->with($model);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Challenge type mismatch.');

        $this->manager()->consume('token123', 'registration');
    }

    public function testConsumeThrowsOnCustomerMismatch(): void
    {
        $createdAt = date('Y-m-d H:i:s', 1000000);
        $model = $this->createConsumeModel([
            'id' => 1,
            'type' => 'registration',
            'challenge_data' => '{"data":"x"}',
            'customer_id' => '99',
            'created_at' => $createdAt,
        ]);

        $this->createCollectionWithModel($model);

        $this->dateTime->method('gmtTimestamp')->willReturn(1000100);

        $this->mockChallengeResource()->expects($this->once())
            ->method('delete')
            ->with($model);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Challenge does not belong to this customer.');

        $this->manager()->consume('token123', 'registration', 42);
    }

    public function testConsumeSkipsCustomerCheckWhenNull(): void
    {
        $createdAt = date('Y-m-d H:i:s', 1000000);
        $model = $this->createConsumeModel([
            'id' => 1,
            'type' => 'authentication',
            'challenge_data' => '{"challenge":"ok"}',
            'customer_id' => '99',
            'created_at' => $createdAt,
        ]);

        $this->createCollectionWithModel($model);

        $this->dateTime->method('gmtTimestamp')->willReturn(1000100);

        $this->mockChallengeResource()->expects($this->once())
            ->method('delete')
            ->with($model);

        $result = $this->manager()->consume('token123', 'authentication');

        $this->assertSame('{"challenge":"ok"}', $result);
    }

    public function testConsumeThrowsOnExpired(): void
    {
        $createdAt = date('Y-m-d H:i:s', 1000000);
        $model = $this->createConsumeModel([
            'id' => 1,
            'type' => 'registration',
            'challenge_data' => '{"data":"x"}',
            'created_at' => $createdAt,
        ]);

        $this->createCollectionWithModel($model);

        // 1000000 + 301 = expired (TTL is 300)
        $this->dateTime->method('gmtTimestamp')->willReturn(1000301);

        $this->mockChallengeResource()->expects($this->once())
            ->method('delete')
            ->with($model);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Challenge has expired.');

        $this->manager()->consume('token123', 'registration');
    }

    public function testCleanExpiredReturnsDeleteCount(): void
    {
        $currentTimestamp = 1000500;
        $this->dateTime->method('gmtTimestamp')->willReturn($currentTimestamp);

        $expectedCutoff = date('Y-m-d H:i:s', $currentTimestamp - 300);

        $connection = $this->createMock(AdapterInterface::class);
        $this->challengeResource->method('getConnection')->willReturn($connection);
        $this->challengeResource->method('getMainTable')->willReturn('passkey_challenge');

        $connection->expects($this->once())
            ->method('delete')
            ->with('passkey_challenge', ['created_at < ?' => $expectedCutoff])
            ->willReturn(5);

        $result = $this->manager()->cleanExpired();

        $this->assertSame(5, $result);
    }
}
