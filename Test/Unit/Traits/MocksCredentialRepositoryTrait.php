<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

trait MocksCredentialRepositoryTrait
{
    private CredentialRepositoryInterface&Stub $credentialRepositoryMock;
    private bool $credentialRepositoryIsMock = false;

    private function createCredentialRepositoryStub(): CredentialRepositoryInterface&Stub
    {
        $this->credentialRepositoryMock = $this->createStub(CredentialRepositoryInterface::class);
        return $this->credentialRepositoryMock;
    }

    /**
     * Replace the stub with a mock object, for tests that set expectations. Call before the subject is built.
     */
    private function mockCredentialRepository(): CredentialRepositoryInterface&MockObject
    {
        if (!$this->credentialRepositoryIsMock) {
            $this->credentialRepositoryMock = $this->createMock(CredentialRepositoryInterface::class);
            $this->credentialRepositoryIsMock = true;
        }
        return $this->credentialRepositoryMock;
    }

    private function configureGetByCustomerId(int $customerId, array $credentials): void
    {
        $this->mockCredentialRepository()->method('getByCustomerId')
            ->with($customerId)
            ->willReturn($credentials);
    }

    private function configureCountByCustomerId(int $customerId, int $count): void
    {
        $this->mockCredentialRepository()->method('countByCustomerId')
            ->with($customerId)
            ->willReturn($count);
    }
}
