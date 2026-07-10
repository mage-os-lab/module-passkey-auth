<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Observer;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Model\Email\CredentialNotifier;
use MageOS\PasskeyAuth\Observer\NotifyCredentialAdded;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotifyCredentialAddedTest extends TestCase
{
    private CredentialNotifier&MockObject $notifierMock;
    private NotifyCredentialAdded $observer;

    protected function setUp(): void
    {
        $this->notifierMock = $this->createMock(CredentialNotifier::class);
        $this->observer = new NotifyCredentialAdded($this->notifierMock);
    }

    private function buildObserver(array $eventData): Observer
    {
        return new Observer(['event' => new Event($eventData)]);
    }

    public function testNotifiesWithFriendlyName(): void
    {
        $credential = $this->createMock(CredentialInterface::class);
        $credential->method('getFriendlyName')->willReturn('Chrome on Windows');

        $this->notifierMock->expects($this->once())
            ->method('notifyAdded')
            ->with(42, 'Chrome on Windows');

        $this->observer->execute($this->buildObserver([
            'customer_id' => 42,
            'credential' => $credential,
        ]));
    }

    public function testNotifiesWithNullNameWhenCredentialMissing(): void
    {
        $this->notifierMock->expects($this->once())
            ->method('notifyAdded')
            ->with(42, null);

        $this->observer->execute($this->buildObserver(['customer_id' => 42]));
    }

    public function testSkipsInvalidCustomerId(): void
    {
        $this->notifierMock->expects($this->never())->method('notifyAdded');

        $this->observer->execute($this->buildObserver(['customer_id' => 0]));
    }
}
