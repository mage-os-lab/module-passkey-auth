<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use MageOS\PasskeyAuth\Model\ChallengeManager;
use PHPUnit\Framework\MockObject\MockObject;

trait MocksChallengeManagerTrait
{
    private ChallengeManager&MockObject $challengeManagerMock;

    private function createChallengeManagerMock(): ChallengeManager&MockObject
    {
        $this->challengeManagerMock = $this->createMock(ChallengeManager::class);
        return $this->challengeManagerMock;
    }

    private function configureCreateChallenge(string $token): void
    {
        $this->challengeManagerMock->method('create')->willReturn($token);
    }

    private function configureConsumeChallenge(string $token, string $data): void
    {
        $this->challengeManagerMock->method('consume')
            ->with($token)
            ->willReturn($data);
    }
}
