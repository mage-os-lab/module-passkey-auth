<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Setup\Patch\Data;

use MageOS\PasskeyAuth\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Pre-release versions defaulted customer/passkey/enabled to 1; 1.0 defaults it to 0. A store that used passkeys
 * on those versions without ever saving the setting would silently lose passkey sign-in on upgrade, so save 1
 * for it. Fresh installs have no credentials and stay disabled.
 */
class KeepPasskeysEnabledForExistingInstalls implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly WriterInterface $configWriter
    ) {
    }

    public function apply(): self
    {
        if ($this->hasCredentials() && !$this->isEnabledSettingSaved()) {
            $this->configWriter->save(Config::XML_PATH_ENABLED, '1', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    private function hasCredentials(): bool
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('passkey_credential'), ['entity_id'])
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }

    private function isEnabledSettingSaved(): bool
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('core_config_data'), ['config_id'])
            ->where('path = ?', Config::XML_PATH_ENABLED)
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }
}
