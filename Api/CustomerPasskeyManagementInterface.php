<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api;

use MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Customer passkey operations for web API consumers. Returns CustomerPasskeyInterface, which leaves out the
 * stored key material; PHP callers that need the full record use CredentialManagementInterface.
 *
 * @api
 */
interface CustomerPasskeyManagementInterface
{
    /**
     * List the customer's passkeys, newest first.
     *
     * @param int $customerId
     * @return \MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterface[]
     */
    public function getPasskeys(int $customerId): array;

    /**
     * Rename one of the customer's passkeys.
     *
     * @param int $customerId
     * @param int $entityId
     * @param string $friendlyName
     * @return \MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterface
     * @throws AuthorizationException
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function renamePasskey(int $customerId, int $entityId, string $friendlyName): CustomerPasskeyInterface;

    /**
     * Verify a WebAuthn attestation response and store the new passkey.
     *
     * @param int $customerId
     * @param string $challengeToken
     * @param string $attestationResponseJson
     * @param string|null $friendlyName
     * @return \MageOS\PasskeyAuth\Api\Data\CustomerPasskeyInterface
     * @throws LocalizedException
     */
    public function verifyRegistration(
        int $customerId,
        string $challengeToken,
        string $attestationResponseJson,
        ?string $friendlyName = null
    ): CustomerPasskeyInterface;
}
