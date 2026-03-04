<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Model\ResourceModel\Credential\Collection;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential\CollectionFactory;
use MageOS\PasskeyAuth\Model\UserHandleGenerator;
use Magento\Framework\DataObject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserHandleGeneratorTest extends TestCase
{
    private CollectionFactory&MockObject $collectionFactory;
    private Collection&MockObject $collection;
    private UserHandleGenerator $generator;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collection = $this->createMock(Collection::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->generator = new UserHandleGenerator($this->collectionFactory);
    }

    public function testGetOrGenerateReturnsExistingHandle(): void
    {
        $existingHandle = 'dGVzdC11c2VyLWhhbmRsZQ==';

        $item = new DataObject(['id' => 1, 'user_handle' => $existingHandle]);
        $this->collection->method('getFirstItem')->willReturn($item);

        $result = $this->generator->getOrGenerate(42);

        $this->assertSame($existingHandle, $result);
    }

    public function testGetOrGenerateCreatesNewHandle(): void
    {
        $item = new DataObject();
        $this->collection->method('getFirstItem')->willReturn($item);

        $result = $this->generator->getOrGenerate(42);

        $this->assertSame(44, strlen($result));
    }

    public function testGetOrGenerateNewHandleIsBase64(): void
    {
        $item = new DataObject();
        $this->collection->method('getFirstItem')->willReturn($item);

        $result = $this->generator->getOrGenerate(42);

        $decoded = base64_decode($result, true);
        $this->assertNotFalse($decoded, 'Return value must be valid base64');
        $this->assertSame(32, strlen($decoded), 'Decoded value must be 32 bytes');
    }
}
