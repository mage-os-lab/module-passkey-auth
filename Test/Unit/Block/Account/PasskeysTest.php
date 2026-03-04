<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Block\Account;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Block\Account\Passkeys;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PasskeysTest extends TestCase
{
    use MocksCustomerSessionTrait;
    use MocksCredentialRepositoryTrait;

    private Context&MockObject $contextMock;
    private Passkeys $block;

    protected function setUp(): void
    {
        $this->contextMock = $this->createMock(Context::class);
        $this->createCustomerSessionMock();
        $this->createCredentialRepositoryMock();

        $this->block = new Passkeys(
            $this->contextMock,
            $this->customerSessionMock,
            $this->credentialRepositoryMock
        );
    }

    public function testGetCredentials(): void
    {
        $customerId = 42;
        $credentials = [
            $this->createMock(CredentialInterface::class),
            $this->createMock(CredentialInterface::class),
        ];

        $this->configureLoggedIn($customerId);
        $this->configureGetByCustomerId($customerId, $credentials);

        $this->assertSame($credentials, $this->block->getCredentials());
    }
}
