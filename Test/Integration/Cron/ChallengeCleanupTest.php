<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Integration\Cron;

use MageOS\PasskeyAuth\Cron\ChallengeCleanup;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge as ChallengeResource;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge\CollectionFactory;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @magentoDbIsolation enabled
 */
class ChallengeCleanupTest extends TestCase
{
    public function testExecuteRemovesExpiredChallenges(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var ChallengeManager $challengeManager */
        $challengeManager = $objectManager->get(ChallengeManager::class);
        /** @var ChallengeResource $resource */
        $resource = $objectManager->get(ChallengeResource::class);

        $staleToken = $challengeManager->create(ChallengeManager::TYPE_AUTHENTICATION, '{}');
        $freshToken = $challengeManager->create(ChallengeManager::TYPE_AUTHENTICATION, '{}');

        $resource->getConnection()->update(
            $resource->getMainTable(),
            ['created_at' => gmdate('Y-m-d H:i:s', time() - 4000)],
            ['token = ?' => $staleToken]
        );

        $objectManager->get(ChallengeCleanup::class)->execute();

        /** @var CollectionFactory $collectionFactory */
        $collectionFactory = $objectManager->get(CollectionFactory::class);

        $stale = $collectionFactory->create()->addFieldToFilter('token', $staleToken);
        $this->assertSame(0, $stale->getSize(), 'Expired challenge should be removed.');

        $fresh = $collectionFactory->create()->addFieldToFilter('token', $freshToken);
        $this->assertSame(1, $fresh->getSize(), 'Fresh challenge should be kept.');
    }
}
