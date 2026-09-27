<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Unit\Setup\Patch\Data;

use MageOS\PasskeyAuth\Setup\Patch\Data\KeepPasskeysEnabledForExistingInstalls;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

class KeepPasskeysEnabledForExistingInstallsTest extends TestCase
{
    /**
     * @var array<string, string|false> First value fetchOne() returns per table
     */
    private array $firstValues = [];

    /**
     * @var array<int, string> Table each select reads, by object ID
     */
    private array $selectTables = [];

    private array $configPathConditions = [];

    public function testEnablesPasskeysWhenCredentialsExistAndSettingWasNeverSaved(): void
    {
        $this->firstValues = ['passkey_credential' => '7', 'core_config_data' => false];

        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())
            ->method('save')
            ->with('customer/passkey/enabled', '1', 'default', 0);

        $this->createPatch($writer)->apply();

        $this->assertSame([['path = ?', 'customer/passkey/enabled']], $this->configPathConditions);
    }

    public function testLeavesFreshInstallDisabled(): void
    {
        $this->firstValues = ['passkey_credential' => false, 'core_config_data' => false];

        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');

        $this->createPatch($writer)->apply();
    }

    public function testKeepsSavedSettingAtAnyScope(): void
    {
        $this->firstValues = ['passkey_credential' => '7', 'core_config_data' => '12'];

        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');

        $this->createPatch($writer)->apply();
    }

    public function testHasNoDependencies(): void
    {
        $this->assertSame([], KeepPasskeysEnabledForExistingInstalls::getDependencies());
    }

    private function createPatch(WriterInterface $writer): KeepPasskeysEnabledForExistingInstalls
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->createSelect());
        $connection->method('fetchOne')->willReturnCallback(
            fn (Select $select) => $this->firstValues[$this->selectTables[spl_object_id($select)]]
        );

        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        return new KeepPasskeysEnabledForExistingInstalls($setup, $writer);
    }

    private function createSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnCallback(function (string $table) use ($select) {
            $this->selectTables[spl_object_id($select)] = $table;
            return $select;
        });
        $select->method('where')->willReturnCallback(function (string $condition, $value) use ($select) {
            $this->configPathConditions[] = [$condition, $value];
            return $select;
        });
        $select->method('limit')->willReturnSelf();

        return $select;
    }
}
