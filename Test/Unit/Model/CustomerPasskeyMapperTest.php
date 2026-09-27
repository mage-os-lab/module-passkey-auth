<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model;

use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterfaceFactory;
use MageOS\PasskeyAuth\Model\CustomerPasskeyMapper;
use MageOS\PasskeyAuth\Model\Data\Credential;
use MageOS\PasskeyAuth\Model\Data\CustomerPasskey;
use PHPUnit\Framework\TestCase;

class CustomerPasskeyMapperTest extends TestCase
{
    private function mapper(): CustomerPasskeyMapper
    {
        $factory = $this->createStub(CustomerPasskeyInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn () => new CustomerPasskey());

        return new CustomerPasskeyMapper($factory);
    }

    public function testMapsDisplayFields(): void
    {
        $passkey = $this->mapper()->map($this->createCredential([
            'friendly_name' => 'iPhone',
            'transports' => 'internal,hybrid',
            'last_used_at' => '2026-03-02 08:30:00',
        ]));

        $this->assertSame(15, $passkey->getId());
        $this->assertSame('iPhone', $passkey->getName());
        $this->assertSame(['internal', 'hybrid'], $passkey->getTransports());
        $this->assertSame('2026-03-01 10:00:00', $passkey->getCreatedAt());
        $this->assertSame('2026-03-02 08:30:00', $passkey->getLastUsedAt());
    }

    public function testLeavesOutKeyMaterialAndIdentifiers(): void
    {
        $passkey = $this->mapper()->map($this->createCredential());

        $this->assertInstanceOf(CustomerPasskey::class, $passkey);
        $this->assertSame(
            ['id', 'name', 'transports', 'created_at', 'last_used_at'],
            array_keys($passkey->getData())
        );
    }

    public function testMapsMissingOptionalFields(): void
    {
        $passkey = $this->mapper()->map($this->createCredential());

        $this->assertNull($passkey->getName());
        $this->assertSame([], $passkey->getTransports());
        $this->assertNull($passkey->getLastUsedAt());
    }

    private function createCredential(array $data = []): Credential
    {
        return new Credential($data + [
            'entity_id' => '15',
            'customer_id' => '42',
            'credential_id' => base64_encode('credential-id'),
            'public_key' => '{"serialized":"credential-record"}',
            'user_handle' => base64_encode('user-handle'),
            'sign_count' => '3',
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'created_at' => '2026-03-01 10:00:00',
        ]);
    }
}
