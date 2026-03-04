<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Model\ChallengeFactory;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge as ChallengeResource;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\Framework\Stdlib\DateTime\DateTime;

class ChallengeManager
{
    private const TTL_SECONDS = 300;

    public function __construct(
        private readonly ChallengeFactory $challengeFactory,
        private readonly ChallengeResource $challengeResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly Random $random,
        private readonly DateTime $dateTime
    ) {
    }

    public function create(string $type, string $challengeData, ?int $customerId = null): string
    {
        $token = $this->random->getRandomString(64);
        $model = $this->challengeFactory->create();
        $model->setData([
            'token' => $token,
            'challenge_data' => $challengeData,
            'type' => $type,
            'customer_id' => $customerId,
        ]);
        $this->challengeResource->save($model);
        return $token;
    }

    public function consume(string $token, string $expectedType): string
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('token', $token);
        $model = $collection->getFirstItem();

        if (!$model->getId()) {
            throw new LocalizedException(__('Invalid or expired challenge token.'));
        }

        if ($model->getData('type') !== $expectedType) {
            $this->challengeResource->delete($model);
            throw new LocalizedException(__('Challenge type mismatch.'));
        }

        $createdAt = strtotime((string) $model->getData('created_at'));
        $now = $this->dateTime->gmtTimestamp();
        if (($now - $createdAt) > self::TTL_SECONDS) {
            $this->challengeResource->delete($model);
            throw new LocalizedException(__('Challenge has expired.'));
        }

        $challengeData = (string) $model->getData('challenge_data');
        $this->challengeResource->delete($model);

        return $challengeData;
    }

    public function cleanExpired(): int
    {
        $collection = $this->collectionFactory->create();
        $cutoff = date('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - self::TTL_SECONDS);
        $collection->addFieldToFilter('created_at', ['lt' => $cutoff]);

        $count = 0;
        foreach ($collection as $model) {
            $this->challengeResource->delete($model);
            $count++;
        }
        return $count;
    }
}
