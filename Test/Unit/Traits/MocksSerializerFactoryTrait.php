<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use MageOS\PasskeyAuth\Model\WebAuthn\SerializerFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Serializer\SerializerInterface;

trait MocksSerializerFactoryTrait
{
    private SerializerFactory&MockObject $serializerFactoryMock;
    private SerializerInterface&MockObject $serializerMock;

    private function createSerializerFactoryMock(): SerializerFactory&MockObject
    {
        $this->serializerFactoryMock = $this->createMock(SerializerFactory::class);
        $this->serializerMock = $this->createMock(SerializerInterface::class);
        $this->serializerFactoryMock->method('get')->willReturn($this->serializerMock);
        return $this->serializerFactoryMock;
    }
}
