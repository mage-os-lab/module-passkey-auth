<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Resolver;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Resolver\CredentialFormatter;
use PHPUnit\Framework\TestCase;

class CredentialFormatterTest extends TestCase
{
    public function testFormatMapsCredentialToGraphQlShape(): void
    {
        $credential = $this->createMock(CredentialInterface::class);
        $credential->method('getEntityId')->willReturn(15);
        $credential->method('getFriendlyName')->willReturn('Chrome on Windows');
        $credential->method('getTransportsArray')->willReturn(['internal', 'hybrid']);
        $credential->method('getCreatedAt')->willReturn('2026-03-01 10:00:00');
        $credential->method('getLastUsedAt')->willReturn(null);

        $result = (new CredentialFormatter())->format($credential);

        $this->assertSame([
            'id' => 15,
            'name' => 'Chrome on Windows',
            'transports' => ['internal', 'hybrid'],
            'created_at' => '2026-03-01 10:00:00',
            'last_used_at' => null,
        ], $result);
    }
}
