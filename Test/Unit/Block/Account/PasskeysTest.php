<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Block\Account;

use MageOS\PasskeyAuth\Api\Data\CredentialInterface;
use MageOS\PasskeyAuth\Block\Account\Passkeys;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCredentialRepositoryTrait;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksCustomerSessionTrait;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class PasskeysTest extends TestCase
{
    use MocksCustomerSessionTrait;
    use MocksCredentialRepositoryTrait;

    private Context&Stub $contextMock;
    private ?Passkeys $block = null;

    protected function setUp(): void
    {
        $this->contextMock = $this->createStub(Context::class);
        $this->createCustomerSessionStub();
        $this->createCredentialRepositoryStub();
    }

    private function block(): Passkeys
    {
        return $this->block ??= new Passkeys(
            $this->contextMock,
            $this->customerSessionMock,
            $this->credentialRepositoryMock
        );
    }

    public function testGetCredentials(): void
    {
        $customerId = 42;
        $credentials = [
            $this->createStub(CredentialInterface::class),
            $this->createStub(CredentialInterface::class),
        ];

        $this->configureLoggedIn($customerId);
        $this->configureGetByCustomerId($customerId, $credentials);

        $this->assertSame($credentials, $this->block()->getCredentials());
    }
}
