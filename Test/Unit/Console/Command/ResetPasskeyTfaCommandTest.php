<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Console\Command;

use MageOS\PasskeyAuth\Console\Command\ResetPasskeyTfaCommand;
use MageOS\PasskeyAuth\Model\AdminTfa\Engine;
use Magento\TwoFactorAuth\Api\UserConfigManagerInterface;
use Magento\User\Model\ResourceModel\User\Collection as UserCollection;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResetPasskeyTfaCommandTest extends TestCase
{
    private UserConfigManagerInterface&Stub $userConfigManager;
    private UserCollectionFactory&Stub $userCollectionFactory;
    private ?ResetPasskeyTfaCommand $command = null;

    protected function setUp(): void
    {
        $this->userConfigManager = $this->createStub(UserConfigManagerInterface::class);
        $this->userCollectionFactory = $this->createStub(UserCollectionFactory::class);
    }

    private function command(): ResetPasskeyTfaCommand
    {
        return $this->command ??= new ResetPasskeyTfaCommand(
            $this->userConfigManager,
            $this->userCollectionFactory
        );
    }

    /**
     * Replace the user config manager stub with a mock object. Call before the command is built.
     */
    private function mockUserConfigManager(): UserConfigManagerInterface&MockObject
    {
        $userConfigManager = $this->createMock(UserConfigManagerInterface::class);
        $this->userConfigManager = $userConfigManager;
        return $userConfigManager;
    }

    public function testCommandHasCorrectName(): void
    {
        $this->assertSame('security:tfa:passkey:reset-all', $this->command()->getName());
    }

    public function testForceOptionSkipsConfirmation(): void
    {
        $definition = $this->command()->getDefinition();
        $this->assertTrue($definition->hasOption('force'));
    }

    public function testExecuteResetsMatchingUsers(): void
    {
        $userConfigManager = $this->mockUserConfigManager();

        $user1 = $this->createStub(User::class);
        $user1->method('getId')->willReturn(1);
        $user1->method('getUserName')->willReturn('admin1');

        $user2 = $this->createStub(User::class);
        $user2->method('getId')->willReturn(2);
        $user2->method('getUserName')->willReturn('admin2');

        $this->mockUserCollection([$user1, $user2]);

        $userConfigManager->method('getProviderConfig')
            ->willReturnCallback(function (int $userId, string $code) {
                return in_array($userId, [1, 2], true) && $code === Engine::CODE
                    ? ['registration' => ['credential_id' => 'abc' . $userId]]
                    : null;
            });

        $userConfigManager->expects($this->exactly(2))
            ->method('resetProviderConfig')
            ->willReturnCallback(function (int $userId, string $code) {
                $this->assertContains([$userId, $code], [
                    [1, Engine::CODE],
                    [2, Engine::CODE],
                ]);
                return true;
            });

        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->with('force')->willReturn(true);

        $output = $this->createStub(OutputInterface::class);

        $command = $this->command();
        $ref = new \ReflectionMethod($command, 'execute');
        $ref->setAccessible(true);
        $result = $ref->invoke($command, $input, $output);

        $this->assertSame(0, $result);
    }

    public function testExecuteReturnsSuccessWhenNoUsersConfigured(): void
    {
        $userConfigManager = $this->mockUserConfigManager();
        $this->mockUserCollection([]);

        $userConfigManager->expects($this->never())->method('resetProviderConfig');

        $input = $this->createStub(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);
        $output->expects($this->once())
            ->method('writeln')
            ->with('<info>No admin users have passkey 2FA configured.</info>');

        $command = $this->command();
        $ref = new \ReflectionMethod($command, 'execute');
        $ref->setAccessible(true);
        $result = $ref->invoke($command, $input, $output);

        $this->assertSame(0, $result);
    }

    private function mockUserCollection(array $users): void
    {
        $collection = $this->createStub(UserCollection::class);
        $collection->method('addFieldToSelect')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('clear')->willReturnSelf();
        $collection->method('getLastPageNumber')->willReturn(1);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($users));
        $this->userCollectionFactory->method('create')->willReturn($collection);
    }
}
