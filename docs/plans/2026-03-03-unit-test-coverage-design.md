# PasskeyAuth Unit Test Coverage Design

**Date**: 2026-03-03
**Scope**: All 27 testable classes, ~130 test methods, 7 shared mock traits
**Convention**: 1:1 test class per source class, PHPUnit MockObject, `declare(strict_types=1)`

## Directory Layout

```
Test/Unit/
├── Traits/
│   ├── MocksConfigTrait.php
│   ├── MocksCredentialRepositoryTrait.php
│   ├── MocksCustomerSessionTrait.php
│   ├── MocksChallengeManagerTrait.php
│   ├── MocksSerializerFactoryTrait.php
│   ├── MocksJsonResultTrait.php
│   └── MocksLoggerTrait.php
├── Model/
│   ├── ConfigTest.php
│   ├── CredentialRepositoryTest.php
│   ├── CredentialManagementTest.php
│   ├── ChallengeManagerTest.php
│   ├── PasskeyTokenServiceTest.php
│   ├── UserHandleGeneratorTest.php
│   ├── RateLimiterTest.php
│   ├── Authentication/
│   │   ├── OptionsGeneratorTest.php
│   │   └── VerifierTest.php
│   ├── Registration/
│   │   ├── OptionsGeneratorTest.php
│   │   └── VerifierTest.php
│   └── WebAuthn/
│       ├── SerializerFactoryTest.php
│       └── CeremonyStepManagerProviderTest.php
├── Controller/
│   ├── Registration/
│   │   ├── OptionsTest.php
│   │   └── VerifyTest.php
│   ├── Authentication/
│   │   ├── OptionsTest.php
│   │   └── VerifyTest.php
│   └── Account/
│       ├── IndexTest.php
│       ├── DeleteTest.php
│       └── RenameTest.php
├── Cron/
│   └── ChallengeCleanupTest.php
├── CustomerData/
│   └── PasskeySectionTest.php
└── Block/
    ├── Account/
    │   └── PasskeysTest.php
    ├── Login/
    │   └── PasskeyButtonTest.php
    └── EnrollmentPromptTest.php
```

## Shared Mock Traits

### MocksConfigTrait
- `createConfigMock(): Config|MockObject`
- `configureEnabled(MockObject $config, bool $enabled)`
- `configureRpId(MockObject $config, string $rpId)`
- `configureAllowedOrigins(MockObject $config, array $origins)`
- `configureMaxCredentials(MockObject $config, int $max)`
- `configurePromptAfterLogin(MockObject $config, bool $enabled)`
- Used by: Both OptionsGenerators, both Verifiers, CeremonyStepManagerProvider, PasskeySection, EnrollmentPrompt, PasskeyButton

### MocksCredentialRepositoryTrait
- `createCredentialRepositoryMock(): CredentialRepositoryInterface|MockObject`
- `configureGetByCustomerId(MockObject $repo, int $customerId, array $credentials)`
- `configureCountByCustomerId(MockObject $repo, int $customerId, int $count)`
- Used by: Both OptionsGenerators, both Verifiers, CredentialManagement, PasskeySection, UserHandleGenerator, Passkeys block

### MocksCustomerSessionTrait
- `createCustomerSessionMock(): Session|MockObject`
- `configureLoggedIn(MockObject $session, int $customerId)`
- `configureNotLoggedIn(MockObject $session)`
- Used by: All 5 controllers requiring auth, PasskeySection, Passkeys block

### MocksChallengeManagerTrait
- `createChallengeManagerMock(): ChallengeManager|MockObject`
- `configureCreateChallenge(MockObject $mgr, string $returnToken)`
- `configureConsumeChallenge(MockObject $mgr, string $token, string $data)`
- `configureConsumeThrows(MockObject $mgr, string $token)`
- Used by: Both OptionsGenerators, both Verifiers

### MocksSerializerFactoryTrait
- `createSerializerFactoryMock(): SerializerFactory|MockObject`
- `configureSerializer(MockObject $factory, SerializerInterface $serializer)`
- Used by: Both OptionsGenerators, both Verifiers

### MocksJsonResultTrait
- `createJsonFactoryMock(): JsonFactory|MockObject`
- `createJsonResultMock(): Json|MockObject`
- `expectJsonResponse(MockObject $result, int $statusCode, array $data)` — sets expectations for setHttpResponseCode + setData
- Used by: All 6 AJAX controllers

### MocksLoggerTrait
- `createLoggerMock(): LoggerInterface|MockObject`
- Used by: Both Verifiers, all 6 controllers, ChallengeCleanup

## Test Cases by Class

### Tier 1: Core Business Logic

#### Authentication/VerifierTest (~12 tests)
**Traits**: MocksConfigTrait, MocksChallengeManagerTrait, MocksSerializerFactoryTrait, MocksCredentialRepositoryTrait, MocksLoggerTrait
- testVerifyThrowsWhenDisabled
- testVerifyThrowsOnInvalidChallengeToken
- testVerifyThrowsOnInvalidResponseType
- testVerifyThrowsWhenCredentialNotFound (+ dispatches failure event)
- testVerifyThrowsWhenValidatorFails (+ logs details, dispatches failure event)
- testVerifyWarnsOnSignCountDecrease (succeeds, logs warning)
- testVerifySucceedsWhenCredentialUpdateFails (logs error, returns token)
- testVerifyThrowsWhenTokenCreationFails
- testVerifySuccessful (updates counter, dispatches event, returns result)
- testVerifyNoWarningWhenBothSignCountsZero
- testVerifyUpdatesLastUsedAt
- testVerifyUpdatesPublicKey

#### Authentication/OptionsGeneratorTest (~8 tests)
**Traits**: MocksConfigTrait, MocksCredentialRepositoryTrait, MocksChallengeManagerTrait, MocksSerializerFactoryTrait
- testGenerateThrowsWhenDisabled
- testGenerateThrowsWhenRateLimited
- testGenerateWithEmailCustomerFound (allowCredentials populated)
- testGenerateWithEmailCustomerNotFound (anti-enumeration: valid response)
- testGenerateWithoutEmail (empty allowCredentials)
- testGenerateCreatesChallengeRecord
- testGenerateReturnsJsonWithChallengeToken
- testGenerateMultipleCredentials

#### Registration/VerifierTest (~12 tests)
**Traits**: MocksConfigTrait, MocksChallengeManagerTrait, MocksSerializerFactoryTrait, MocksCredentialRepositoryTrait, MocksLoggerTrait
- testVerifyThrowsWhenDisabled
- testVerifyAcceptsNullFriendlyName
- testVerifyConvertsEmptyFriendlyNameToNull
- testVerifyThrowsOnFriendlyNameTooLong
- testVerifyThrowsOnFriendlyNameWithXss (< > &)
- testVerifyThrowsOnInvalidChallengeToken
- testVerifyThrowsOnChallengeCustomerMismatch
- testVerifyThrowsOnInvalidResponseType
- testVerifyThrowsWhenValidatorFails (logs, dispatches event)
- testVerifyThrowsOnMaxCredentialsRaceCondition
- testVerifySuccessful (saves credential, dispatches event, returns DTO)
- testVerifyExtractsTransports

#### Registration/OptionsGeneratorTest (~8 tests)
**Traits**: MocksConfigTrait, MocksCredentialRepositoryTrait, MocksChallengeManagerTrait, MocksSerializerFactoryTrait
- testGenerateThrowsWhenDisabled
- testGenerateThrowsWhenRateLimited
- testGenerateThrowsWhenMaxCredentialsReached
- testGenerateSucceedsUnderMaxCredentials
- testGenerateExcludesExistingCredentials
- testGenerateCreatesChallengeWithCustomerId
- testGenerateReturnsJsonWithChallengeToken
- testGenerateCallsUserHandleGenerator

### Tier 2: Data & Infrastructure

#### CredentialRepositoryTest (~15 tests)
**Traits**: (none — all mocks are repo-internal)
- testGetByIdFound, testGetByIdNotFound
- testGetByCredentialIdFound, testGetByCredentialIdNotFound
- testGetByCustomerIdWithResults, testGetByCustomerIdEmpty
- testSaveNewCredential, testSaveExistingCredential, testSaveWithLastUsedAt
- testSaveThrowsOnMissingCustomerId, testSaveThrowsOnEmptyCredentialId, testSaveThrowsOnEmptyPublicKey, testSaveThrowsOnNegativeSignCount
- testDeleteSuccess, testDeleteNotFound
- testCountByCustomerId

#### ChallengeManagerTest (~10 tests)
- testCreateRegistrationType, testCreateAuthenticationType, testCreateWithCustomerId
- testConsumeValid
- testConsumeThrowsOnTokenNotFound
- testConsumeThrowsOnTypeMismatch
- testConsumeThrowsOnCustomerMismatch
- testConsumeThrowsOnExpired
- testConsumeBoundaryTTL (300s exactly)
- testCleanExpired

#### RateLimiterTest (~7 tests)
- testCheckOptionsRateFirstRequest
- testCheckOptionsRateUnderLimit
- testCheckOptionsRateExceedsLimit
- testCheckVerifyFailRateUnderLimit
- testCheckVerifyFailRateExceedsLimit
- testCheckVerifyFailRateFirstRequest
- testRecordVerifyFailure

#### CredentialManagementTest (~7 tests)
- testDeleteCredentialByOwner
- testDeleteCredentialByNonOwner
- testRenameCredentialSuccess
- testRenameCredentialNotOwner
- testValidateFriendlyNameEmpty
- testValidateFriendlyNameTooLong
- testValidateFriendlyNameXss

#### ConfigTest (~5 tests)
**Traits**: (none — tests Config itself)
- testGetRpIdValid
- testGetRpIdThrowsOnMissingHost
- testGetAllowedOriginsHttps
- testGetAllowedOriginsWithCustomPort
- testGetAllowedOriginsThrowsOnMissingScheme

#### PasskeyTokenServiceTest (~2 tests)
- testCreateTokenForCustomer
- testCreateTokenSetsCorrectUserType

#### UserHandleGeneratorTest (~3 tests)
**Traits**: MocksCredentialRepositoryTrait
- testGetOrGenerateExistingHandle
- testGetOrGenerateNewHandle
- testGetOrGenerateReturnsDifferentForDifferentCustomers

### Tier 3: Controllers

#### Registration/OptionsTest (~5 tests)
**Traits**: MocksCustomerSessionTrait, MocksJsonResultTrait, MocksLoggerTrait
- testExecuteNotLoggedIn (401)
- testExecuteSuccess (200)
- testExecuteLocalizedException (400)
- testExecuteGenericException (400 + log)
- testValidateForCsrfWithAjaxHeader

#### Registration/VerifyTest (~5 tests)
**Traits**: MocksCustomerSessionTrait, MocksJsonResultTrait, MocksLoggerTrait
- testExecuteNotLoggedIn (401)
- testExecuteSuccess (200)
- testExecuteLocalizedException (400)
- testExecuteGenericException (400 + log)
- testValidateForCsrfWithAjaxHeader

#### Authentication/OptionsTest (~4 tests)
**Traits**: MocksJsonResultTrait, MocksLoggerTrait
- testExecuteSuccess
- testExecuteLocalizedException (400)
- testExecuteGenericException (400 + log)
- testExecuteWithoutEmail

#### Authentication/VerifyTest (~7 tests)
**Traits**: MocksCustomerSessionTrait, MocksJsonResultTrait, MocksLoggerTrait
- testExecuteRateLimited (blocks before verify)
- testExecuteSuccess (sets session, clears cookie)
- testExecuteSuccessNoCookie
- testExecuteLocalizedException (records failure)
- testExecuteGenericException (records failure)
- testExecuteExtractsClientIp
- testValidateForCsrfWithAjaxHeader

#### Account/IndexTest (~2 tests)
**Traits**: MocksCustomerSessionTrait
- testExecuteNotLoggedIn (redirect)
- testExecuteLoggedIn (page result)

#### Account/DeleteTest (~5 tests)
**Traits**: MocksCustomerSessionTrait, MocksJsonResultTrait, MocksLoggerTrait
- testExecuteNotLoggedIn (401)
- testExecuteSuccess (200)
- testExecuteLocalizedException (400)
- testExecuteGenericException (400 + log)
- testValidateForCsrfWithAjaxHeader

#### Account/RenameTest (~5 tests)
**Traits**: MocksCustomerSessionTrait, MocksJsonResultTrait, MocksLoggerTrait
- testExecuteNotLoggedIn (401)
- testExecuteSuccess (200)
- testExecuteLocalizedException (400)
- testExecuteGenericException (400 + log)
- testValidateForCsrfWithAjaxHeader

### Tier 4: Support Classes

#### ChallengeCleanupTest (~2 tests)
**Traits**: MocksLoggerTrait
- testExecuteNoExpired (no log)
- testExecuteSomeExpired (logs count)

#### PasskeySectionTest (~5 tests)
**Traits**: MocksConfigTrait, MocksCustomerSessionTrait, MocksCredentialRepositoryTrait
- testNotLoggedIn (show_enrollment_prompt: false)
- testFeatureDisabled (false)
- testPromptDisabled (false)
- testEnabledWithPasskeys (false)
- testEnabledWithoutPasskeys (true)

#### Block/Account/PasskeysTest (~1 test)
**Traits**: MocksCustomerSessionTrait, MocksCredentialRepositoryTrait
- testGetCredentials

#### Block/Login/PasskeyButtonTest (~1 test)
**Traits**: MocksConfigTrait
- testGetUiMode

#### Block/EnrollmentPromptTest (~3 tests)
**Traits**: MocksConfigTrait
- testToHtmlFeatureDisabled (empty)
- testToHtmlPromptDisabled (empty)
- testToHtmlEnabled (parent HTML)

#### WebAuthn/SerializerFactoryTest (~2 tests)
- testGetReturnsSameInstance (singleton)
- testGetCreatesSerializer

#### WebAuthn/CeremonyStepManagerProviderTest (~2 tests)
**Traits**: MocksConfigTrait
- testGetCreationCeremony
- testGetRequestCeremony

## Totals

- **27 test classes** + **7 mock traits** = 34 files
- **~130 test methods**
- **Estimated ~150-200 assertions**
