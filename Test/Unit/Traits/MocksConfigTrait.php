<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Traits;

use MageOS\PasskeyAuth\Model\Config;
use PHPUnit\Framework\MockObject\MockObject;

trait MocksConfigTrait
{
    private Config&MockObject $configMock;

    private function createConfigMock(): Config&MockObject
    {
        $this->configMock = $this->createMock(Config::class);
        return $this->configMock;
    }

    private function configureEnabled(bool $enabled): void
    {
        $this->configMock->method('isEnabled')->willReturn($enabled);
    }

    private function configureMaxCredentials(int $max): void
    {
        $this->configMock->method('getMaxCredentials')->willReturn($max);
    }

    private function configurePromptAfterLogin(bool $enabled): void
    {
        $this->configMock->method('isPromptAfterLoginEnabled')->willReturn($enabled);
    }
}
