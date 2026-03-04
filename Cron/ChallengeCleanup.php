<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Cron;

use MageOS\PasskeyAuth\Model\ChallengeManager;
use Psr\Log\LoggerInterface;

class ChallengeCleanup
{
    public function __construct(
        private readonly ChallengeManager $challengeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $count = $this->challengeManager->cleanExpired();
        if ($count > 0) {
            $this->logger->info(sprintf('PasskeyAuth: Cleaned %d expired challenge(s).', $count));
        }
    }
}
