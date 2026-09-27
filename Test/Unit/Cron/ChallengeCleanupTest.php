<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

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
        $this->mockChallengeManager();
        $this->mockLogger();

        $this->cron = new ChallengeCleanup(
            $this->challengeManagerMock,
            $this->loggerMock
        );
    }

    public function testExecuteNoExpired(): void
    {
        $this->mockChallengeManager()->expects($this->once())
            ->method('cleanExpired')
            ->willReturn(0);

        $this->mockLogger()->expects($this->never())
            ->method('info');

        $this->cron->execute();
    }

    public function testExecuteSomeExpired(): void
    {
        $this->mockChallengeManager()->expects($this->once())
            ->method('cleanExpired')
            ->willReturn(5);

        $this->mockLogger()->expects($this->once())
            ->method('info')
            ->with($this->stringContains('5'));

        $this->cron->execute();
    }
}
