<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Model\ResourceModel\Credential\Collection;
use MageOS\PasskeyAuth\Model\ResourceModel\Credential\CollectionFactory;
use MageOS\PasskeyAuth\Model\UserHandleGenerator;
use Magento\Framework\DataObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class UserHandleGeneratorTest extends TestCase
{
    private CollectionFactory&Stub $collectionFactory;
    private Collection&Stub $collection;
    private UserHandleGenerator $generator;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->collection = $this->createStub(Collection::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->generator = new UserHandleGenerator($this->collectionFactory);
    }

    public function testGetOrGenerateReturnsDecodedExistingHandle(): void
    {
        $rawHandle = random_bytes(32);
        $this->configureStoredHandles([base64_encode($rawHandle)]);

        $result = $this->generator->getOrGenerate(42);

        $this->assertSame($rawHandle, $result);
    }

    public function testGetOrGenerateDoesNotGrowHandleAcrossRegistrations(): void
    {
        $stored = [];
        $this->collection->method('getIterator')->willReturnCallback(
            function () use (&$stored) {
                return new \ArrayIterator(array_map(
                    fn (string $handle) => new DataObject(['user_handle' => $handle]),
                    $stored
                ));
            }
        );

        $first = $this->generator->getOrGenerate(42);
        // What Registration\Verifier stores for the new credential
        $stored[] = base64_encode($first);
        $second = $this->generator->getOrGenerate(42);

        $this->assertSame($first, $second);
        $this->assertSame(32, strlen($second));
    }

    public function testGetOrGenerateUsesOldestRow(): void
    {
        $orderedBy = null;
        $this->collection->method('setOrder')->willReturnCallback(
            function (string $field, string $direction) use (&$orderedBy) {
                $orderedBy = [$field, $direction];
                return $this->collection;
            }
        );
        $this->configureStoredHandles([base64_encode('oldest-handle'), base64_encode('newer-handle')]);

        $result = $this->generator->getOrGenerate(42);

        $this->assertSame(['entity_id', 'ASC'], $orderedBy);
        $this->assertSame('oldest-handle', $result);
    }

    public function testGetOrGenerateSkipsDriftedHandles(): void
    {
        // Pre-fix rows: each stored handle is base64 of the previous one, so they grow 44 -> 60 -> 80 bytes
        $drifted = base64_encode(base64_encode(base64_encode(random_bytes(32))));
        $valid = base64_encode(random_bytes(32));
        $this->configureStoredHandles([base64_encode($drifted), 'not base64!', '', base64_encode($valid)]);

        $result = $this->generator->getOrGenerate(42);

        $this->assertSame(80, strlen($drifted));
        $this->assertSame($valid, $result);
    }

    public function testGetOrGenerateAcceptsSixtyFourByteHandle(): void
    {
        $handle = str_repeat('h', 64);
        $this->configureStoredHandles([base64_encode($handle)]);

        $this->assertSame($handle, $this->generator->getOrGenerate(42));
    }

    public function testGetOrGenerateCreatesNewHandleWhenNoneStored(): void
    {
        $this->configureStoredHandles([]);

        $result = $this->generator->getOrGenerate(42);

        $this->assertSame(32, strlen($result));
    }

    public function testGetOrGenerateCreatesNewHandleWhenAllStoredAreUnusable(): void
    {
        $this->configureStoredHandles([base64_encode(str_repeat('x', 65))]);

        $result = $this->generator->getOrGenerate(42);

        $this->assertSame(32, strlen($result));
    }

    /**
     * @param string[] $storedHandles user_handle column values, oldest first
     */
    private function configureStoredHandles(array $storedHandles): void
    {
        $items = array_map(
            fn (string $handle) => new DataObject(['user_handle' => $handle]),
            $storedHandles
        );
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator($items));
    }
}
