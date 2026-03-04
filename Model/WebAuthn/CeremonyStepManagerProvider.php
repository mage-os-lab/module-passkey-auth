<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\WebAuthn;

use MageOS\PasskeyAuth\Model\Config;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

class CeremonyStepManagerProvider
{
    private ?CeremonyStepManager $creationCeremony = null;
    private ?CeremonyStepManager $requestCeremony = null;

    public function __construct(
        private readonly Config $config,
        private readonly AttestationStatementSupportManager $attestationStatementSupportManager
    ) {
    }

    public function getCreationCeremony(): CeremonyStepManager
    {
        if ($this->creationCeremony === null) {
            $this->creationCeremony = $this->buildFactory()->creationCeremony();
        }
        return $this->creationCeremony;
    }

    public function getRequestCeremony(): CeremonyStepManager
    {
        if ($this->requestCeremony === null) {
            $this->requestCeremony = $this->buildFactory()->requestCeremony();
        }
        return $this->requestCeremony;
    }

    private function buildFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins($this->config->getAllowedOrigins());
        $factory->setAttestationStatementSupportManager($this->attestationStatementSupportManager);
        return $factory;
    }
}
