<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\WebAuthn;

use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\Denormalizer\WebauthnSerializerFactory as BaseSerializerFactory;

class SerializerFactory
{
    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly AttestationStatementSupportManager $attestationStatementSupportManager
    ) {
    }

    public function get(): SerializerInterface
    {
        if ($this->serializer === null) {
            $factory = new BaseSerializerFactory($this->attestationStatementSupportManager);
            $this->serializer = $factory->create();
        }
        return $this->serializer;
    }
}
