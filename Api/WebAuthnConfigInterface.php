<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Api;

/**
 * Relying-party settings for one WebAuthn context (storefront or admin).
 */
interface WebAuthnConfigInterface
{
    /**
     * Relying party ID (the host the credentials are scoped to).
     *
     * @return string
     */
    public function getRpId(): string;

    /**
     * Human-readable relying party name shown by the authenticator.
     *
     * @return string
     */
    public function getRpName(): string;

    /**
     * Allowed origins for WebAuthn ceremony validation.
     *
     * @return string[]
     */
    public function getAllowedOrigins(): array;

    /**
     * User verification requirement: 'required', 'preferred' or 'discouraged'.
     *
     * @return string
     */
    public function getUserVerification(): string;

    /**
     * Authenticator attachment: 'platform', 'cross-platform', or null for any.
     *
     * @return string|null
     */
    public function getAuthenticatorAttachment(): ?string;

    /**
     * Attestation conveyance preference: 'none', 'indirect', 'direct' or 'enterprise'.
     *
     * @return string
     */
    public function getAttestationConveyance(): string;

    /**
     * Resident key (discoverable credential) requirement.
     *
     * @return string
     */
    public function getResidentKeyRequirement(): string;

    /**
     * Ceremony timeout in milliseconds.
     *
     * @return int
     */
    public function getCeremonyTimeout(): int;
}
