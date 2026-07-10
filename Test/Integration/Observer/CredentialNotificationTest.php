<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Integration\Observer;

use MageOS\PasskeyAuth\Api\CredentialManagementInterface;
use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Model\Email\CredentialNotifier;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use PHPUnit\Framework\TestCase;

/**
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CredentialNotificationTest extends TestCase
{
    private TransportBuilderMock $transportBuilder;

    protected function setUp(): void
    {
        $this->transportBuilder = Bootstrap::getObjectManager()->get(TransportBuilderMock::class);
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture current_store customer/passkey/notify_credential_changes 1
     */
    public function testAddedNotificationIsSentToCustomer(): void
    {
        /** @var CredentialNotifier $notifier */
        $notifier = Bootstrap::getObjectManager()->get(CredentialNotifier::class);
        $notifier->notifyAdded(1, 'Chrome on Windows');

        $message = $this->transportBuilder->getSentMessage();

        $this->assertNotNull($message, 'A passkey-added email should have been sent.');
        $this->assertStringContainsString('passkey was added', (string) $message->getSubject());
        $this->assertStringContainsString(
            'customer@example.com',
            implode(',', array_map(
                static fn ($address) => $address->getEmail(),
                $message->getTo()
            ))
        );
    }

    /**
     * @magentoDataFixture MageOS_PasskeyAuth::Test/Integration/_files/customer_with_passkey.php
     * @magentoConfigFixture current_store customer/passkey/notify_credential_changes 1
     */
    public function testDeletingCredentialSendsRemovedNotification(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var CredentialRepositoryInterface $repository */
        $repository = $objectManager->get(CredentialRepositoryInterface::class);
        /** @var CredentialManagementInterface $management */
        $management = $objectManager->get(CredentialManagementInterface::class);

        $credentials = $repository->getByCustomerId(1);
        $credential = reset($credentials);

        $management->deleteCredential(1, (int) $credential->getEntityId());

        $message = $this->transportBuilder->getSentMessage();

        $this->assertNotNull($message, 'A passkey-removed email should have been sent.');
        $this->assertStringContainsString('passkey was removed', (string) $message->getSubject());
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoConfigFixture current_store customer/passkey/notify_credential_changes 0
     */
    public function testNoEmailWhenNotificationsDisabled(): void
    {
        /** @var CredentialNotifier $notifier */
        $notifier = Bootstrap::getObjectManager()->get(CredentialNotifier::class);
        $notifier->notifyAdded(1, 'Chrome on Windows');

        $this->assertNull($this->transportBuilder->getSentMessage());
    }
}
