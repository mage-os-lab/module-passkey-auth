<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use MageOS\PasskeyAuth\Model\ChallengeManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

trait MocksChallengeManagerTrait
{
    private ChallengeManager&Stub $challengeManagerMock;
    private bool $challengeManagerIsMock = false;

    private function createChallengeManagerStub(): ChallengeManager&Stub
    {
        $this->challengeManagerMock = $this->createStub(ChallengeManager::class);
        return $this->challengeManagerMock;
    }

    /**
     * Replace the stub with a mock object, for tests that set expectations. Call before the subject is built.
     */
    private function mockChallengeManager(): ChallengeManager&MockObject
    {
        if (!$this->challengeManagerIsMock) {
            $this->challengeManagerMock = $this->createMock(ChallengeManager::class);
            $this->challengeManagerIsMock = true;
        }
        return $this->challengeManagerMock;
    }

    private function configureCreateChallenge(string $token): void
    {
        $this->challengeManagerMock->method('create')->willReturn($token);
    }

    private function configureConsumeChallenge(string $token, string $data): void
    {
        $this->mockChallengeManager()->method('consume')
            ->with($token)
            ->willReturn($data);
    }
}
