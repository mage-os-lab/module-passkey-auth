<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Resolver;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterfaceFactory;
use MageOS\PasskeyAuth\Model\CustomerPasskeyMapper;
use MageOS\PasskeyAuth\Model\Data\CustomerPasskey;
use MageOS\PasskeyAuth\Model\Resolver\CredentialFormatter;
use PHPUnit\Framework\TestCase;

class CredentialFormatterTest extends TestCase
{
    public function testFormatMapsCredentialToGraphQlShape(): void
    {
        $credential = $this->createStub(CredentialInterface::class);
        $credential->method('getEntityId')->willReturn(15);
        $credential->method('getFriendlyName')->willReturn('Chrome on Windows');
        $credential->method('getTransportsArray')->willReturn(['internal', 'hybrid']);
        $credential->method('getCreatedAt')->willReturn('2026-03-01 10:00:00');
        $credential->method('getLastUsedAt')->willReturn(null);

        $factory = $this->createStub(CustomerPasskeyInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn () => new CustomerPasskey());

        $result = (new CredentialFormatter(new CustomerPasskeyMapper($factory)))->format($credential);

        $this->assertSame([
            'id' => 15,
            'name' => 'Chrome on Windows',
            'transports' => ['internal', 'hybrid'],
            'created_at' => '2026-03-01 10:00:00',
            'last_used_at' => null,
        ], $result);
    }
}
