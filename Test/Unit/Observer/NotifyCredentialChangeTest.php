<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Observer;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Config;
use MageOS\PasskeyAuth\Model\Email\CredentialNotifier;
use MageOS\PasskeyAuth\Observer\NotifyCredentialChange;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotifyCredentialChangeTest extends TestCase
{
    private CredentialNotifier&MockObject $notifierMock;
    private NotifyCredentialChange $observer;

    protected function setUp(): void
    {
        $this->notifierMock = $this->createMock(CredentialNotifier::class);
        $this->observer = new NotifyCredentialChange($this->notifierMock, Config::XML_PATH_ADDED_EMAIL_TEMPLATE);
    }

    public function testNotifiesWithCredentialNameAndTemplatePath(): void
    {
        $credential = $this->createMock(CredentialInterface::class);
        $credential->method('getCustomerId')->willReturn(42);
        $credential->method('getFriendlyName')->willReturn('Chrome on Windows');

        $this->notifierMock->expects($this->once())
            ->method('notify')
            ->with(42, 'Chrome on Windows', Config::XML_PATH_ADDED_EMAIL_TEMPLATE);

        $this->observer->execute($this->buildObserver(['credential' => $credential]));
    }

    public function testSkipsWithoutCredential(): void
    {
        $this->notifierMock->expects($this->never())->method('notify');

        $this->observer->execute($this->buildObserver(['customer_id' => 42]));
    }

    public function testSkipsInvalidCustomerId(): void
    {
        $credential = $this->createMock(CredentialInterface::class);
        $credential->method('getCustomerId')->willReturn(0);

        $this->notifierMock->expects($this->never())->method('notify');

        $this->observer->execute($this->buildObserver(['credential' => $credential]));
    }

    private function buildObserver(array $data): Observer
    {
        return new Observer(['event' => new Event($data)]);
    }
}
