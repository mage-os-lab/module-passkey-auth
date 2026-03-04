<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\WebAuthn;

use MageOS\PasskeyAuth\Model\Config;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

class CeremonyStepManagerProvider
{
    public function __construct(
        private readonly Config $config,
        private readonly AttestationStatementSupportManager $attestationStatementSupportManager
    ) {
    }

    public function getCreationCeremony(): CeremonyStepManager
    {
        return $this->buildFactory()->creationCeremony();
    }

    public function getRequestCeremony(): CeremonyStepManager
    {
        return $this->buildFactory()->requestCeremony();
    }

    private function buildFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins($this->config->getAllowedOrigins());
        $factory->setAttestationStatementSupportManager($this->attestationStatementSupportManager);
        return $factory;
    }
}
