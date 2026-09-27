<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Model\Resolver;

use MageOS\PasskeyAuth\Api\AuthenticationVerifierInterface;
use MageOS\PasskeyAuth\Api\Data\AuthenticationResultInterface;
use MageOS\PasskeyAuth\Model\Resolver\VerifyAuthentication;
use MageOS\PasskeyAuth\Test\Unit\Traits\MocksLoggerTrait;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthenticationException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class VerifyAuthenticationTest extends TestCase
{
    use MocksLoggerTrait;

    private const INPUT = ['input' => ['challenge_token' => 'tok', 'assertion_response' => '{}']];

    private AuthenticationVerifierInterface&Stub $verifier;
    private ?VerifyAuthentication $resolver = null;

    protected function setUp(): void
    {
        $this->createLoggerStub();
        $this->verifier = $this->createStub(AuthenticationVerifierInterface::class);
    }

    private function resolve(): array
    {
        $this->resolver ??= new VerifyAuthentication($this->verifier, $this->loggerMock);

        return $this->resolver->resolve(
            $this->createStub(Field::class),
            null,
            $this->createStub(ResolveInfo::class),
            null,
            self::INPUT
        );
    }

    public function testReturnsCustomerToken(): void
    {
        $result = $this->createStub(AuthenticationResultInterface::class);
        $result->method('getToken')->willReturn('customer-token');
        $this->verifier->method('verify')->willReturn($result);

        $this->assertSame(['customer_token' => 'customer-token'], $this->resolve());
    }

    public function testLogsRejectionAsWarningAndHidesReason(): void
    {
        $this->verifier->method('verify')
            ->willThrowException(new LocalizedException(__('Invalid or expired challenge token.')));

        $this->mockLogger()->expects($this->once())
            ->method('warning')
            ->with('GraphQL passkey authentication failed', ['reason' => 'Invalid or expired challenge token.']);
        $this->mockLogger()->expects($this->never())->method('error');

        $this->expectException(GraphQlAuthenticationException::class);
        $this->expectExceptionMessage('Passkey verification failed. Please try again.');

        $this->resolve();
    }
}
