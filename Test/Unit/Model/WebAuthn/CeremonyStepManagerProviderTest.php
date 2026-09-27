<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

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
    private WebAuthnConfigInterface $config;

    protected function setUp(): void
    {
        $this->config = $this->createStub(WebAuthnConfigInterface::class);
        $this->config->method('getAllowedOrigins')->willReturn(['https://example.com']);

        $this->provider = new CeremonyStepManagerProvider(new AttestationStatementSupportManager());
    }

    public function testGetCreationCeremonyReturnsCeremonyStepManager(): void
    {
        $this->assertInstanceOf(CeremonyStepManager::class, $this->provider->getCreationCeremony($this->config));
    }

    public function testGetRequestCeremonyReturnsCeremonyStepManager(): void
    {
        $this->assertInstanceOf(CeremonyStepManager::class, $this->provider->getRequestCeremony($this->config));
    }
}
