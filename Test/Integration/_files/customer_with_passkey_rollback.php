<?php

declare(strict_types=1);

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

$objectManager = Bootstrap::getObjectManager();

/** @var CredentialRepositoryInterface $credentialRepository */
$credentialRepository = $objectManager->get(CredentialRepositoryInterface::class);

foreach ($credentialRepository->getByCustomerId(1) as $credential) {
    $credentialRepository->delete($credential);
}

Resolver::getInstance()->requireDataFixture('Magento/Customer/_files/customer_rollback.php');
