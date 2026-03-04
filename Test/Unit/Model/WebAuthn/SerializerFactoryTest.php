<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\WebAuthn;

use MageOS\PasskeyAuth\Model\WebAuthn\SerializerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;

class SerializerFactoryTest extends TestCase
{
    private SerializerFactory $serializerFactory;

    protected function setUp(): void
    {
        $this->serializerFactory = new SerializerFactory(
            new AttestationStatementSupportManager()
        );
    }

    public function testGetReturnsSameInstance(): void
    {
        $first = $this->serializerFactory->get();
        $second = $this->serializerFactory->get();

        $this->assertSame($first, $second);
    }

    public function testGetReturnsSerializerInterface(): void
    {
        $this->assertInstanceOf(SerializerInterface::class, $this->serializerFactory->get());
    }
}
