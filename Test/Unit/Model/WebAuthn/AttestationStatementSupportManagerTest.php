<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\WebAuthn;

use MageOS\PasskeyAuth\Model\WebAuthn\AttestationStatementSupportManager;
use PHPUnit\Framework\TestCase;

class AttestationStatementSupportManagerTest extends TestCase
{
    public function testRegistersAllAttestationFormats(): void
    {
        $manager = new AttestationStatementSupportManager();

        foreach (['none', 'fido-u2f', 'packed', 'tpm', 'android-key', 'apple'] as $format) {
            $this->assertTrue($manager->has($format), $format);
        }
    }
}
