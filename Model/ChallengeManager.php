<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

use MageOS\PasskeyAuth\Model\ChallengeFactory;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge as ChallengeResource;
use MageOS\PasskeyAuth\Model\ResourceModel\Challenge\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;

class ChallengeManager
{
    public const TYPE_REGISTRATION = 'registration';
    public const TYPE_AUTHENTICATION = 'authentication';

    private const TTL_SECONDS = 300;

    public function __construct(
        private readonly ChallengeFactory $challengeFactory,
        private readonly ChallengeResource $challengeResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly DateTime $dateTime
    ) {
    }

    public function create(string $type, string $challengeData, ?int $customerId = null): string
    {
        $token = bin2hex(random_bytes(32));
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

    public function consume(string $token, string $expectedType, ?int $customerId = null): string
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

        if ($customerId !== null) {
            $storedCustomerId = $model->getData('customer_id') ? (int) $model->getData('customer_id') : null;
            if ($storedCustomerId !== $customerId) {
                $this->challengeResource->delete($model);
                throw new LocalizedException(__('Challenge does not belong to this customer.'));
            }
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
        $cutoff = date('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - self::TTL_SECONDS);
        $connection = $this->challengeResource->getConnection();
        return (int) $connection->delete(
            $this->challengeResource->getMainTable(),
            ['created_at < ?' => $cutoff]
        );
    }
}
