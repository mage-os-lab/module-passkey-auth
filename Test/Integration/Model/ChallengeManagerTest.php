<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Test\Integration\Model;

use MageOS\PasskeyAuth\Model\ChallengeManager;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge as ChallengeResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @magentoDbIsolation enabled
 */
class ChallengeManagerTest extends TestCase
{
    private ChallengeManager $challengeManager;
    private ChallengeResource $challengeResource;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->challengeManager = $objectManager->get(ChallengeManager::class);
        $this->challengeResource = $objectManager->get(ChallengeResource::class);
    }

    public function testCreateAndConsumeRoundTrip(): void
    {
        $token = $this->challengeManager->create(ChallengeManager::TYPE_AUTHENTICATION, '{"challenge":"abc"}');

        $this->assertNotEmpty($token);

        $data = $this->challengeManager->consume($token, ChallengeManager::TYPE_AUTHENTICATION);

        $this->assertSame('{"challenge":"abc"}', $data);
    }

    public function testChallengeIsSingleUse(): void
    {
        $token = $this->challengeManager->create(ChallengeManager::TYPE_AUTHENTICATION, '{}');
        $this->challengeManager->consume($token, ChallengeManager::TYPE_AUTHENTICATION);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid or expired challenge token.');
        $this->challengeManager->consume($token, ChallengeManager::TYPE_AUTHENTICATION);
    }

    public function testConsumeRejectsTypeMismatch(): void
    {
        $token = $this->challengeManager->create(ChallengeManager::TYPE_REGISTRATION, '{}', 1);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Challenge type mismatch.');
        $this->challengeManager->consume($token, ChallengeManager::TYPE_AUTHENTICATION);
    }

    public function testConsumeRejectsWrongCustomer(): void
    {
        $token = $this->challengeManager->create(ChallengeManager::TYPE_REGISTRATION, '{}', 1);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Challenge does not belong to this customer.');
        $this->challengeManager->consume($token, ChallengeManager::TYPE_REGISTRATION, 2);
    }

    public function testConsumeRejectsExpiredChallenge(): void
    {
        $token = $this->challengeManager->create(ChallengeManager::TYPE_AUTHENTICATION, '{}');
        $this->backdateChallenge($token, 400);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Challenge has expired.');
        $this->challengeManager->consume($token, ChallengeManager::TYPE_AUTHENTICATION);
    }

    public function testCleanExpiredRemovesOnlyStaleChallenges(): void
    {
        $staleToken = $this->challengeManager->create(ChallengeManager::TYPE_AUTHENTICATION, '{}');
        $freshToken = $this->challengeManager->create(ChallengeManager::TYPE_AUTHENTICATION, '{"fresh":true}');
        $this->backdateChallenge($staleToken, 400);

        $removed = $this->challengeManager->cleanExpired();

        $this->assertGreaterThanOrEqual(1, $removed);
        // Fresh challenge still consumable
        $this->assertSame('{"fresh":true}', $this->challengeManager->consume(
            $freshToken,
            ChallengeManager::TYPE_AUTHENTICATION
        ));
    }

    private function backdateChallenge(string $token, int $seconds): void
    {
        $connection = $this->challengeResource->getConnection();
        $connection->update(
            $this->challengeResource->getMainTable(),
            ['created_at' => gmdate('Y-m-d H:i:s', time() - $seconds)],
            ['token = ?' => $token]
        );
    }
}
