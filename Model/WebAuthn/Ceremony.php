<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Model\WebAuthn;

use MageOS\PasskeyAuth\Api\WebAuthnConfigInterface;
use MageOS\PasskeyAuth\Model\ChallengeManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn registration and authentication ceremonies for one relying-party config.
 *
 * Callers own credential lookup and storage; this class owns option building, challenge
 * issue/consume, response parsing and validation.
 */
class Ceremony
{
    private const ALG_ES256 = -7;
    private const ALG_RS256 = -257;

    public function __construct(
        private readonly WebAuthnConfigInterface $config,
        private readonly ChallengeManager $challengeManager,
        private readonly SerializerFactory $serializerFactory,
        private readonly CeremonyStepManagerProvider $ceremonyStepManagerProvider,
        private readonly Json $json
    ) {
    }

    /**
     * Build creation options and store the challenge.
     *
     * @param PublicKeyCredentialUserEntity $user
     * @param PublicKeyCredentialDescriptor[] $excludeCredentials
     * @param string $challengeType
     * @param int|null $customerId
     * @return array Options as sent to the browser, plus 'challengeToken'
     */
    public function createRegistrationOptions(
        PublicKeyCredentialUserEntity $user,
        array $excludeCredentials,
        string $challengeType,
        ?int $customerId = null
    ): array {
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create($this->config->getRpName(), $this->config->getRpId()),
            user: $user,
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', self::ALG_ES256),
                PublicKeyCredentialParameters::create('public-key', self::ALG_RS256),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                authenticatorAttachment: $this->config->getAuthenticatorAttachment(),
                userVerification: $this->config->getUserVerification(),
                residentKey: $this->config->getResidentKeyRequirement(),
            ),
            attestation: $this->config->getAttestationConveyance(),
            excludeCredentials: $excludeCredentials,
            timeout: $this->config->getCeremonyTimeout(),
        );

        return $this->issueChallenge($options, $challengeType, $customerId);
    }

    /**
     * Build request options and store the challenge.
     *
     * @param PublicKeyCredentialDescriptor[] $allowCredentials
     * @param string $challengeType
     * @param int|null $customerId
     * @return array Options as sent to the browser, plus 'challengeToken'
     */
    public function createAuthenticationOptions(
        array $allowCredentials,
        string $challengeType,
        ?int $customerId = null
    ): array {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->config->getRpId(),
            allowCredentials: $allowCredentials,
            userVerification: $this->config->getUserVerification(),
            timeout: $this->config->getCeremonyTimeout(),
        );

        return $this->issueChallenge($options, $challengeType, $customerId);
    }

    /**
     * Consume the challenge and validate an attestation response.
     *
     * @param string $challengeToken
     * @param string $responseJson
     * @param string $challengeType
     * @param int|null $customerId
     * @return PublicKeyCredentialSource|\Webauthn\CredentialRecord The new credential
     * @throws LocalizedException When the challenge or response is malformed
     * @throws \Throwable When webauthn-lib rejects the response
     */
    public function verifyRegistration(
        string $challengeToken,
        string $responseJson,
        string $challengeType,
        ?int $customerId = null
    ) {
        $error = __('Invalid attestation response.');
        $creationOptions = $this->deserialize(
            $this->challengeManager->consume($challengeToken, $challengeType, $customerId),
            PublicKeyCredentialCreationOptions::class,
            $error
        );

        $response = $this->deserialize($responseJson, PublicKeyCredential::class, $error)->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new LocalizedException($error);
        }

        return AuthenticatorAttestationResponseValidator::create(
            $this->ceremonyStepManagerProvider->getCreationCeremony($this->config)
        )->check($response, $creationOptions, $this->config->getRpId());
    }

    /**
     * Consume the challenge and parse an assertion, so the caller can look up the credential it names.
     *
     * @param string $challengeToken
     * @param string $responseJson
     * @param string $challengeType
     * @return array{0: PublicKeyCredential, 1: PublicKeyCredentialRequestOptions}
     * @throws LocalizedException
     */
    public function loadAssertion(string $challengeToken, string $responseJson, string $challengeType): array
    {
        $error = __('Invalid assertion response.');
        $requestOptions = $this->deserialize(
            $this->challengeManager->consume($challengeToken, $challengeType),
            PublicKeyCredentialRequestOptions::class,
            $error
        );

        $credential = $this->deserialize($responseJson, PublicKeyCredential::class, $error);
        if (!$credential->response instanceof AuthenticatorAssertionResponse) {
            throw new LocalizedException($error);
        }

        return [$credential, $requestOptions];
    }

    /**
     * Validate a parsed assertion against the stored credential.
     *
     * @param PublicKeyCredential $credential From loadAssertion()
     * @param PublicKeyCredentialRequestOptions $requestOptions From loadAssertion()
     * @param PublicKeyCredentialSource|\Webauthn\CredentialRecord $storedSource From deserializeSource()
     * @return PublicKeyCredentialSource|\Webauthn\CredentialRecord The credential with updated counter
     * @throws \Throwable When webauthn-lib rejects the assertion
     */
    public function verifyAssertion(
        PublicKeyCredential $credential,
        PublicKeyCredentialRequestOptions $requestOptions,
        $storedSource
    ) {
        /** @var AuthenticatorAssertionResponse $response */
        $response = $credential->response;

        return AuthenticatorAssertionResponseValidator::create(
            $this->ceremonyStepManagerProvider->getRequestCeremony($this->config)
        )->check($storedSource, $response, $requestOptions, $this->config->getRpId(), $storedSource->userHandle);
    }

    /**
     * Serialize a credential source for storage.
     *
     * @param PublicKeyCredentialSource|\Webauthn\CredentialRecord $source
     * @return string
     */
    public function serializeSource($source): string
    {
        return $this->serializerFactory->get()->serialize($source, 'json');
    }

    /**
     * Restore a stored credential source.
     *
     * webauthn-lib 5.3 deprecates PublicKeyCredentialSource and denormalizes it to its parent CredentialRecord,
     * so ask for CredentialRecord whenever the installed version has it.
     *
     * @param string $json From serializeSource()
     * @return PublicKeyCredentialSource|\Webauthn\CredentialRecord
     */
    public function deserializeSource(string $json)
    {
        $type = class_exists(\Webauthn\CredentialRecord::class)
            ? \Webauthn\CredentialRecord::class
            : PublicKeyCredentialSource::class;

        return $this->serializerFactory->get()->deserialize($json, $type, 'json');
    }

    /**
     * Parse untrusted JSON into a webauthn-lib object.
     *
     * webauthn-lib and Symfony throw TypeError, RangeException and serializer errors on malformed input, and
     * return a plain array for JSON such as {} or [], so every failure becomes the caller's LocalizedException.
     *
     * @template T of object
     * @param string $json
     * @param class-string<T> $type
     * @param Phrase $error
     * @return T
     * @throws LocalizedException
     */
    private function deserialize(string $json, string $type, Phrase $error): object
    {
        try {
            $result = $this->serializerFactory->get()->deserialize($json, $type, 'json');
        } catch (\Throwable $e) {
            throw new LocalizedException($error, $e instanceof \Exception ? $e : null);
        }

        if (!$result instanceof $type) {
            throw new LocalizedException($error);
        }

        return $result;
    }

    private function issueChallenge(PublicKeyCredentialOptions $options, string $challengeType, ?int $customerId): array
    {
        $optionsJson = $this->serializerFactory->get()->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);

        $optionsArray = $this->json->unserialize($optionsJson);
        $optionsArray['challengeToken'] = $this->challengeManager->create($challengeType, $optionsJson, $customerId);

        return $optionsArray;
    }
}
