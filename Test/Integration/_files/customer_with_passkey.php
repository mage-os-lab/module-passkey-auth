<?php
/**
 * Creates a passkey credential for the standard fixture customer (ID 1).
 */

declare(strict_types=1);

use MageOS\PasskeyAuth\Api\CredentialRepositoryInterface;
use MageOS\PasskeyAuth\Api\Data\CredentialInterfaceFactory;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

Resolver::getInstance()->requireDataFixture('Magento/Customer/_files/customer.php');

$objectManager = Bootstrap::getObjectManager();

/** @var CredentialInterfaceFactory $credentialFactory */
$credentialFactory = $objectManager->get(CredentialInterfaceFactory::class);
/** @var CredentialRepositoryInterface $credentialRepository */
$credentialRepository = $objectManager->get(CredentialRepositoryInterface::class);

$credential = $credentialFactory->create();
$credential->setCustomerId(1);
$credential->setCredentialId(base64_encode('integration-test-credential-id'));
$credential->setPublicKey('{"fixture":"not-a-real-credential-source"}');
$credential->setUserHandle(base64_encode('integration-test-user-handle'));
$credential->setSignCount(5);
$credential->setTransports('internal,hybrid');
$credential->setFriendlyName('Integration Test Passkey');
$credential->setAaguid('00000000-0000-0000-0000-000000000000');

$credentialRepository->save($credential);
