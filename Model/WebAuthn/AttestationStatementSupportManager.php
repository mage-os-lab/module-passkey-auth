<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\WebAuthn;

use Cose\Algorithm\Manager as AlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\ECDSA\ES384;
use Cose\Algorithm\Signature\ECDSA\ES512;
use Cose\Algorithm\Signature\EdDSA\Ed25519;
use Cose\Algorithm\Signature\RSA\PS256;
use Cose\Algorithm\Signature\RSA\PS384;
use Cose\Algorithm\Signature\RSA\PS512;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Algorithm\Signature\RSA\RS384;
use Cose\Algorithm\Signature\RSA\RS512;
use Webauthn\AttestationStatement\AndroidKeyAttestationStatementSupport;
use Webauthn\AttestationStatement\AppleAttestationStatementSupport;
use Webauthn\AttestationStatement\AttestationStatementSupportManager as BaseManager;
use Webauthn\AttestationStatement\FidoU2FAttestationStatementSupport;
use Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Webauthn\AttestationStatement\TPMAttestationStatementSupport;

/**
 * Registers every attestation format webauthn-lib supports, so responses that carry a real
 * attestation statement (not just "none") can be parsed and their signatures checked.
 *
 * Statements are not checked against a trust anchor (no FIDO metadata), so this does not
 * prove anything about the authenticator's make or model.
 */
class AttestationStatementSupportManager extends BaseManager
{
    public function __construct()
    {
        parent::__construct([
            FidoU2FAttestationStatementSupport::create(),
            PackedAttestationStatementSupport::create(AlgorithmManager::create()->add(
                ES256::create(),
                ES384::create(),
                ES512::create(),
                RS256::create(),
                RS384::create(),
                RS512::create(),
                PS256::create(),
                PS384::create(),
                PS512::create(),
                Ed25519::create()
            )),
            TPMAttestationStatementSupport::create(),
            AndroidKeyAttestationStatementSupport::create(),
            AppleAttestationStatementSupport::create(),
        ]);
    }
}
