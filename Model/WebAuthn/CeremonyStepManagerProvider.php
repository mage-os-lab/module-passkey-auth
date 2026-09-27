<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\WebAuthn;

use MageOS\PasskeyAuth\Api\WebAuthnConfigInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

class CeremonyStepManagerProvider
{
    public function __construct(
        private readonly AttestationStatementSupportManager $attestationStatementSupportManager
    ) {
    }

    public function getCreationCeremony(WebAuthnConfigInterface $config): CeremonyStepManager
    {
        return $this->buildFactory($config)->creationCeremony();
    }

    public function getRequestCeremony(WebAuthnConfigInterface $config): CeremonyStepManager
    {
        return $this->buildFactory($config)->requestCeremony();
    }

    private function buildFactory(WebAuthnConfigInterface $config): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins($config->getAllowedOrigins());
        $factory->setAttestationStatementSupportManager($this->attestationStatementSupportManager);
        return $factory;
    }
}
