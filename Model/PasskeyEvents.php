<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model;

/**
 * Event names and failure reason codes dispatched by this module. Payload keys are listed in etc/events.xml.
 *
 * @api
 */
class PasskeyEvents
{
    public const CREDENTIAL_REGISTER_AFTER = 'passkey_credential_register_after';
    public const CREDENTIAL_REMOVE_AFTER = 'passkey_credential_remove_after';
    public const REGISTRATION_FAILURE = 'passkey_registration_failure';
    public const AUTHENTICATION_SUCCESS = 'passkey_authentication_success';
    public const AUTHENTICATION_FAILURE = 'passkey_authentication_failure';

    /**
     * No stored credential matches the assertion, or it belongs to a customer of another website.
     */
    public const REASON_CREDENTIAL_NOT_FOUND = 'credential_not_found';

    /**
     * webauthn-lib rejected the attestation or assertion (signature, origin, counter, ...).
     */
    public const REASON_VERIFICATION_FAILED = 'verification_failed';
}
