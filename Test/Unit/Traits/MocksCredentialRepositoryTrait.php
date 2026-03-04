<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;

trait MocksCredentialRepositoryTrait
{
    private CredentialRepositoryInterface&MockObject $credentialRepositoryMock;

    private function createCredentialRepositoryMock(): CredentialRepositoryInterface&MockObject
    {
        $this->credentialRepositoryMock = $this->createMock(CredentialRepositoryInterface::class);
        return $this->credentialRepositoryMock;
    }

    private function configureGetByCustomerId(int $customerId, array $credentials): void
    {
        $this->credentialRepositoryMock->method('getByCustomerId')
            ->with($customerId)
            ->willReturn($credentials);
    }

    private function configureCountByCustomerId(int $customerId, int $count): void
    {
        $this->credentialRepositoryMock->method('countByCustomerId')
            ->with($customerId)
            ->willReturn($count);
    }
}
