<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\WebAuthn;

use MageOS\PasskeyAuth\Api\WebAuthnConfigInterface;
use MageOS\PasskeyAuth\Model\WebAuthn\CeremonyStepManagerProvider;
use PHPUnit\Framework\TestCase;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\CeremonyStep\CeremonyStepManager;

class CeremonyStepManagerProviderTest extends TestCase
{
    private CeremonyStepManagerProvider $provider;

    protected function setUp(): void
    {
        $configMock = $this->createMock(WebAuthnConfigInterface::class);
        $configMock->method('getAllowedOrigins')->willReturn(['https://example.com']);

        $this->provider = new CeremonyStepManagerProvider(
            $configMock,
            new AttestationStatementSupportManager()
        );
    }

    public function testGetCreationCeremonyReturnsCeremonyStepManager(): void
    {
        $this->assertInstanceOf(CeremonyStepManager::class, $this->provider->getCreationCeremony());
    }

    public function testGetRequestCeremonyReturnsCeremonyStepManager(): void
    {
        $this->assertInstanceOf(CeremonyStepManager::class, $this->provider->getRequestCeremony());
    }
}
