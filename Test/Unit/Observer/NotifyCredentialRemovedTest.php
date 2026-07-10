<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Observer;

use MageOS\PasskeyAuth\Model\Email\CredentialNotifier;
use MageOS\PasskeyAuth\Observer\NotifyCredentialRemoved;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotifyCredentialRemovedTest extends TestCase
{
    private CredentialNotifier&MockObject $notifierMock;
    private NotifyCredentialRemoved $observer;

    protected function setUp(): void
    {
        $this->notifierMock = $this->createMock(CredentialNotifier::class);
        $this->observer = new NotifyCredentialRemoved($this->notifierMock);
    }

    private function buildObserver(array $eventData): Observer
    {
        return new Observer(['event' => new Event($eventData)]);
    }

    public function testNotifiesWithFriendlyName(): void
    {
        $this->notifierMock->expects($this->once())
            ->method('notifyRemoved')
            ->with(7, 'Safari on iOS');

        $this->observer->execute($this->buildObserver([
            'customer_id' => 7,
            'credential_id' => 12,
            'friendly_name' => 'Safari on iOS',
        ]));
    }

    public function testNormalizesNonStringNameToNull(): void
    {
        $this->notifierMock->expects($this->once())
            ->method('notifyRemoved')
            ->with(7, null);

        $this->observer->execute($this->buildObserver([
            'customer_id' => 7,
            'friendly_name' => null,
        ]));
    }

    public function testSkipsInvalidCustomerId(): void
    {
        $this->notifierMock->expects($this->never())->method('notifyRemoved');

        $this->observer->execute($this->buildObserver([]));
    }
}
