<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;

trait MocksLoggerTrait
{
    private LoggerInterface&Stub $loggerMock;
    private bool $loggerIsMock = false;

    private function createLoggerStub(): LoggerInterface&Stub
    {
        $this->loggerMock = $this->createStub(LoggerInterface::class);
        return $this->loggerMock;
    }

    /**
     * Replace the stub with a mock object, for tests that set expectations. Call before the subject is built.
     */
    private function mockLogger(): LoggerInterface&MockObject
    {
        if (!$this->loggerIsMock) {
            $this->loggerMock = $this->createMock(LoggerInterface::class);
            $this->loggerIsMock = true;
        }
        return $this->loggerMock;
    }
}
