<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use MageOS\PasskeyAuth\Model\WebAuthn\SerializerFactory;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Serializer\SerializerInterface;

trait MocksSerializerFactoryTrait
{
    private SerializerFactory&Stub $serializerFactoryMock;
    private SerializerInterface&Stub $serializerMock;

    private function createSerializerFactoryStub(): SerializerFactory&Stub
    {
        $this->serializerFactoryMock = $this->createStub(SerializerFactory::class);
        $this->serializerMock = $this->createStub(SerializerInterface::class);
        $this->serializerFactoryMock->method('get')->willReturn($this->serializerMock);
        return $this->serializerFactoryMock;
    }
}
