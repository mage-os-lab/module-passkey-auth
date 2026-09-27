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
    private string|false $credentialId = false;

    /**
     * @var array<int, array{config_id: string, path: string, scope: string, scope_id: int}> Saved config rows
     */
    private array $configRows = [];

    /**
     * @var array<int, string> Table each select reads, by object ID
     */
    private array $selectTables = [];

    /**
     * @var array<int, array<string, mixed>> Column conditions each select applies, by object ID
     */
    private array $selectConditions = [];

    public function testEnablesPasskeysWhenCredentialsExistAndSettingWasNeverSaved(): void
    {
        $this->credentialId = '7';

        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())
            ->method('save')
            ->with('customer/passkey/enabled', '1', 'default', 0);

        $this->createPatch($writer)->apply();
    }

    public function testLeavesFreshInstallDisabled(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');

        $this->createPatch($writer)->apply();
    }

    public function testKeepsSettingSavedAtDefaultScope(): void
    {
        $this->credentialId = '7';
        $this->configRows = [$this->configRow('12', 'default', 0)];

        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');

        $this->createPatch($writer)->apply();
    }

    public function testEnablesPasskeysAtDefaultScopeWhenSettingWasSavedOnlyForAWebsite(): void
    {
        $this->credentialId = '7';
        $this->configRows = [$this->configRow('12', 'websites', 1)];

        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())
            ->method('save')
            ->with('customer/passkey/enabled', '1', 'default', 0);

        $this->createPatch($writer)->apply();
    }

    public function testHasNoDependencies(): void
    {
        $this->assertSame([], KeepPasskeysEnabledForExistingInstalls::getDependencies());
    }

    private function configRow(string $configId, string $scope, int $scopeId): array
    {
        return [
            'config_id' => $configId,
            'path' => 'customer/passkey/enabled',
            'scope' => $scope,
            'scope_id' => $scopeId,
        ];
    }

    private function createPatch(WriterInterface $writer): KeepPasskeysEnabledForExistingInstalls
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->createSelect());
        $connection->method('fetchOne')->willReturnCallback(fn (Select $select) => $this->fetchOne($select));

        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        return new KeepPasskeysEnabledForExistingInstalls($setup, $writer);
    }

    private function fetchOne(Select $select): string|false
    {
        $id = spl_object_id($select);
        if ($this->selectTables[$id] === 'passkey_credential') {
            return $this->credentialId;
        }

        foreach ($this->configRows as $row) {
            $matches = true;
            foreach ($this->selectConditions[$id] ?? [] as $column => $value) {
                $matches = $matches && (string) $row[$column] === (string) $value;
            }
            if ($matches) {
                return $row['config_id'];
            }
        }

        return false;
    }

    private function createSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnCallback(function (string $table) use ($select) {
            $this->selectTables[spl_object_id($select)] = $table;
            return $select;
        });
        $select->method('where')->willReturnCallback(function (string $condition, $value) use ($select) {
            $this->assertMatchesRegularExpression('/^\w+ = \?$/', $condition);
            $this->selectConditions[spl_object_id($select)][strtok($condition, ' ')] = $value;
            return $select;
        });
        $select->method('limit')->willReturnSelf();

        return $select;
    }
}
