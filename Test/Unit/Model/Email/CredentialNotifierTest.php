<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Email;

use MageOS\PasskeyAuth\Model\Config;
use MageOS\PasskeyAuth\Model\Email\CredentialNotifier;
use Magento\Customer\Api\CustomerNameGenerationInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CredentialNotifierTest extends TestCase
{
    private CustomerRepositoryInterface&Stub $customerRepository;
    private StoreManagerInterface&Stub $storeManager;
    private ?int $sentFromStoreId = null;
    private LoggerInterface&Stub $logger;
    private ?CredentialNotifier $notifier = null;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);

        $store = $this->createStub(Store::class);
        $store->method('getFrontendName')->willReturn('Main Store');
        $this->storeManager->method('getStore')->willReturn($store);
    }

    private function notifier(): CredentialNotifier
    {
        $config = $this->createStub(Config::class);
        $config->method('isCredentialNotificationEnabled')->willReturn(true);
        $config->method('getEmailTemplate')->willReturn('customer_passkey_added_email_template');
        $config->method('getNotificationIdentity')->willReturn('general');

        $transportBuilder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateVars', 'setFromByScope', 'addTo'] as $method) {
            $transportBuilder->method($method)->willReturnSelf();
        }
        $transportBuilder->method('setTemplateOptions')->willReturnCallback(
            function (array $options) use ($transportBuilder) {
                $this->sentFromStoreId = $options['store'];
                return $transportBuilder;
            }
        );
        $transportBuilder->method('getTransport')->willReturn($this->createStub(TransportInterface::class));

        return $this->notifier ??= new CredentialNotifier(
            $config,
            $this->customerRepository,
            $this->createStub(CustomerNameGenerationInterface::class),
            $transportBuilder,
            $this->storeManager,
            $this->logger
        );
    }

    private function configureCustomer(int $storeId, int $websiteId): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getStoreId')->willReturn($storeId);
        $customer->method('getWebsiteId')->willReturn($websiteId);
        $customer->method('getEmail')->willReturn('jane@example.com');
        $this->customerRepository->method('getById')->willReturn($customer);
    }

    private function createStore(int $id): Store&Stub
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        return $store;
    }

    public function testSendsFromCustomerStore(): void
    {
        $this->configureCustomer(storeId: 3, websiteId: 2);

        $this->notifier()->notify(42, 'My Key', Config::XML_PATH_ADDED_EMAIL_TEMPLATE);

        $this->assertSame(3, $this->sentFromStoreId);
    }

    public function testAdminCreatedCustomerUsesWebsiteDefaultStore(): void
    {
        $this->configureCustomer(storeId: 0, websiteId: 2);

        $website = $this->createStub(Website::class);
        $website->method('getDefaultStore')->willReturn($this->createStore(5));
        $this->storeManager->method('getWebsite')->willReturnMap([[2, $website]]);
        $this->storeManager->method('getDefaultStoreView')->willReturn($this->createStore(1));

        $this->notifier()->notify(42, 'My Key', Config::XML_PATH_ADDED_EMAIL_TEMPLATE);

        $this->assertSame(5, $this->sentFromStoreId);
    }

    public function testFallsBackToDefaultStoreViewWithoutWebsite(): void
    {
        $this->configureCustomer(storeId: 0, websiteId: 0);
        $this->storeManager->method('getDefaultStoreView')->willReturn($this->createStore(1));

        $this->notifier()->notify(42, 'My Key', Config::XML_PATH_ADDED_EMAIL_TEMPLATE);

        $this->assertSame(1, $this->sentFromStoreId);
    }

    public function testFallsBackToDefaultStoreViewWhenWebsiteIsGone(): void
    {
        $this->configureCustomer(storeId: 0, websiteId: 7);
        $this->storeManager->method('getWebsite')
            ->willThrowException(new NoSuchEntityException(__('The website with id 7 was not found.')));
        $this->storeManager->method('getDefaultStoreView')->willReturn($this->createStore(1));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->never())->method('error');

        $this->notifier()->notify(42, 'My Key', Config::XML_PATH_ADDED_EMAIL_TEMPLATE);

        $this->assertSame(1, $this->sentFromStoreId);
    }

    public function testLogsErrorsThatAreNotExceptions(): void
    {
        $this->customerRepository->method('getById')->willThrowException(new \TypeError('Unexpected type'));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to send passkey notification email', [
                'exception' => 'Unexpected type',
                'customer_id' => 42,
                'template' => Config::XML_PATH_ADDED_EMAIL_TEMPLATE,
            ]);

        $this->notifier()->notify(42, 'My Key', Config::XML_PATH_ADDED_EMAIL_TEMPLATE);

        $this->assertNull($this->sentFromStoreId);
    }
}
