<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

trait MocksLoggerTrait
{
    private LoggerInterface&MockObject $loggerMock;

    private function createLoggerMock(): LoggerInterface&MockObject
    {
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        return $this->loggerMock;
    }
}
