<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Cron;

use MageOS\PasskeyAuth\Cron\ChallengeCleanup;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksChallengeManagerTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use PHPUnit\Framework\TestCase;

class ChallengeCleanupTest extends TestCase
{
    use MocksChallengeManagerTrait;
    use MocksLoggerTrait;

    private ChallengeCleanup $cron;

    protected function setUp(): void
    {
        $this->createChallengeManagerMock();
        $this->createLoggerMock();

        $this->cron = new ChallengeCleanup(
            $this->challengeManagerMock,
            $this->loggerMock
        );
    }

    public function testExecuteNoExpired(): void
    {
        $this->challengeManagerMock->expects($this->once())
            ->method('cleanExpired')
            ->willReturn(0);

        $this->loggerMock->expects($this->never())
            ->method('info');

        $this->cron->execute();
    }

    public function testExecuteSomeExpired(): void
    {
        $this->challengeManagerMock->expects($this->once())
            ->method('cleanExpired')
            ->willReturn(5);

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with($this->stringContains('5'));

        $this->cron->execute();
    }
}
