# PasskeyAuth Unit Test Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add PHPUnit unit test coverage for all 27 testable classes in the PasskeyAuth module (~130 test methods).

**Architecture:** 1:1 test-class-per-source-class under `Test/Unit/`, 7 shared mock traits under `Test/Unit/Traits/`. PHPUnit 10 with intersection types for mocks. Tests follow existing MageOS conventions (see `AdminActivityLog/Test/Unit/` for reference).

**Tech Stack:** PHPUnit 10, Magento Framework test bootstrap, MockObject

**Run command:** `php vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/MageOS/PasskeyAuth/Test/Unit/ --testdox`

---

### Task 1: Create shared mock traits

**Files:**
- Create: `Test/Unit/Traits/MocksConfigTrait.php`
- Create: `Test/Unit/Traits/MocksCredentialRepositoryTrait.php`
- Create: `Test/Unit/Traits/MocksCustomerSessionTrait.php`
- Create: `Test/Unit/Traits/MocksChallengeManagerTrait.php`
- Create: `Test/Unit/Traits/MocksSerializerFactoryTrait.php`
- Create: `Test/Unit/Traits/MocksJsonResultTrait.php`
- Create: `Test/Unit/Traits/MocksLoggerTrait.php`

**Step 1: Create all 7 trait files**

Each trait provides a `create*Mock()` method plus helper methods for common configurations. All traits use `$this->createMock()` which is available from PHPUnit TestCase.

**MocksConfigTrait:** Mocks `MageOS\PasskeyAuth\Model\Config`. Helpers: `configureEnabled(bool)`, `configureMaxCredentials(int)`, `configurePromptAfterLogin(bool)`.

**MocksCredentialRepositoryTrait:** Mocks `MageOS\PasskeyAuth\Api\CredentialRepositoryInterface`. Helpers: `configureGetByCustomerId(int, array)`, `configureCountByCustomerId(int, int)`.

**MocksCustomerSessionTrait:** Mocks `Magento\Customer\Model\Session`. Helpers: `configureLoggedIn(int)`, `configureNotLoggedIn()`.

**MocksChallengeManagerTrait:** Mocks `MageOS\PasskeyAuth\Model\ChallengeManager`. Helpers: `configureCreateChallenge(string)`, `configureConsumeChallenge(string, string)`.

**MocksSerializerFactoryTrait:** Mocks `MageOS\PasskeyAuth\Model\WebAuthn\SerializerFactory`. Returns a mock `Symfony\Component\Serializer\SerializerInterface`.

**MocksJsonResultTrait:** Mocks `Magento\Framework\Controller\Result\JsonFactory` and creates a trackable `Json` result mock. The Json mock's `setHttpResponseCode` and `setData` return `$this` for chaining.

**MocksLoggerTrait:** Mocks `Psr\Log\LoggerInterface`.

**Step 2: Commit**
```bash
git add Test/Unit/Traits/
git commit -m "Add shared mock traits for PasskeyAuth unit tests"
```

---

### Task 2: ConfigTest

**Files:**
- Create: `Test/Unit/Model/ConfigTest.php`

**Traits used:** None (tests Config itself)

**Tests (5):**
- `testGetRpIdReturnsHostFromBaseUrl` — store returns `https://example.com/`, assert `example.com`
- `testGetRpIdThrowsOnMissingHost` — store returns `not-a-url`, expect RuntimeException
- `testGetAllowedOriginsReturnsHttpsOrigin` — store returns `https://shop.example.com/`, assert `['https://shop.example.com']`
- `testGetAllowedOriginsIncludesCustomPort` — store returns `https://shop.example.com:8443/`, assert `['https://shop.example.com:8443']`
- `testGetAllowedOriginsThrowsOnMissingScheme` — store returns `//no-scheme.com`, expect RuntimeException

**Mocks:** `ScopeConfigInterface`, `StoreManagerInterface`, `Store` (mock `getBaseUrl()`)

**Step 1: Write test file with all 5 tests**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/ConfigTest.php
git commit -m "Add unit tests for Config"
```

---

### Task 3: RateLimiterTest

**Files:**
- Create: `Test/Unit/Model/RateLimiterTest.php`

**Traits used:** None

**Tests (7):**
- `testCheckOptionsRateFirstRequest` — cache returns `false` (no key), should pass + save `1`
- `testCheckOptionsRateUnderLimit` — cache returns `5`, should pass + save `6`
- `testCheckOptionsRateAtLimit` — cache returns `10`, expect LocalizedException
- `testCheckVerifyFailRateUnderLimit` — cache returns `3`, should pass (no increment)
- `testCheckVerifyFailRateAtLimit` — cache returns `5`, expect LocalizedException
- `testCheckVerifyFailRateFirstRequest` — cache returns `false`, should pass
- `testRecordVerifyFailure` — cache returns `2`, should save `3` with 900s TTL

**Mocks:** `CacheInterface`

**Key details:** `checkOptionsRate` calls `checkOnly` then `increment` (2 cache loads). `checkVerifyFailRate` only calls `checkOnly` (1 cache load). `recordVerifyFailure` only calls `increment` (1 cache load + 1 save). Cache key is `passkey_options_` + md5 or `passkey_verify_fail_` + md5.

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/RateLimiterTest.php
git commit -m "Add unit tests for RateLimiter"
```

---

### Task 4: ChallengeManagerTest

**Files:**
- Create: `Test/Unit/Model/ChallengeManagerTest.php`

**Traits used:** None

**Tests (10):**
- `testCreateReturnsChallengeToken` — verify factory create called, resource save called, returns hex string (64 chars)
- `testCreateSetsAllFields` — verify setData called with token, type, challenge_data, customer_id
- `testCreateWithNullCustomerId` — verify customer_id=null passed
- `testConsumeReturnsData` — collection returns model with matching type, valid TTL, returns challenge_data, deletes model
- `testConsumeThrowsOnTokenNotFound` — model->getId() returns null, expect LocalizedException
- `testConsumeThrowsOnTypeMismatch` — model type != expectedType, deletes model, expect LocalizedException
- `testConsumeThrowsOnCustomerMismatch` — customerId provided but doesn't match stored, deletes, expect LocalizedException
- `testConsumeSkipsCustomerCheckWhenNull` — customerId=null, doesn't check stored customer
- `testConsumeThrowsOnExpired` — created_at > 300s ago, deletes, expect LocalizedException
- `testCleanExpiredReturnsDeleteCount` — verify connection->delete called with cutoff date, returns count

**Mocks:** `ChallengeFactory`, `ChallengeResource` (also mock `getConnection`, `getMainTable`), `CollectionFactory`, `DateTime`

**Key details:** For `consume`, mock collection to return a model mock. The `DateTime::gmtTimestamp()` returns current timestamp. For TTL check, set `created_at` and mock `gmtTimestamp` to control the time difference. The `cleanExpired` method uses `$this->challengeResource->getConnection()->delete()`.

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/ChallengeManagerTest.php
git commit -m "Add unit tests for ChallengeManager"
```

---

### Task 5: CredentialRepositoryTest

**Files:**
- Create: `Test/Unit/Model/CredentialRepositoryTest.php`

**Traits used:** None

**Tests (15):**
- `testGetByIdFound` — resource loads model, model->getId() returns ID, returns DTO
- `testGetByIdNotFound` — model->getId() returns null, expect NoSuchEntityException
- `testGetByCredentialIdFound` — resource loads by credential_id field, returns DTO
- `testGetByCredentialIdNotFound` — expect NoSuchEntityException
- `testGetByCustomerIdWithResults` — collection returns 2 models, returns 2 DTOs
- `testGetByCustomerIdEmpty` — collection empty, returns []
- `testSaveNewCredential` — credential->getEntityId() returns null, creates new model, saves
- `testSaveExistingCredential` — credential->getEntityId() returns 1, loads existing, saves
- `testSaveExistingCredentialNotFound` — loads model but getId() returns null, expect CouldNotSaveException
- `testSaveWithLastUsedAt` — credential->getLastUsedAt() returns date string, model sets last_used_at
- `testSaveThrowsOnMissingCustomerId` — credential->getCustomerId() returns 0, expect CouldNotSaveException
- `testSaveThrowsOnEmptyCredentialId` — credential->getCredentialId() returns '', expect CouldNotSaveException
- `testSaveThrowsOnEmptyPublicKey` — credential->getPublicKey() returns '', expect CouldNotSaveException
- `testSaveThrowsOnNegativeSignCount` — credential->getSignCount() returns -1, expect CouldNotSaveException
- `testDeleteSuccess` — model loaded, getId() returns ID, resource delete called
- `testDeleteNotFound` — model->getId() null, expect CouldNotDeleteException
- `testCountByCustomerId` — collection filtered, getSize returns 3

**Mocks:** `CredentialResource`, `CredentialModelFactory` (returns Credential model mock), `CredentialDTOFactory` (returns CredentialDTO mock), `CollectionFactory`

**Note:** The DTO mock must implement `CredentialInterface`. Use `$this->createMock(CredentialInterface::class)` for input credentials. For the `toDTO` return, the factory creates a real `Data\Credential` (DataObject) — but for unit tests, mock the factory to return a CredentialInterface mock.

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/CredentialRepositoryTest.php
git commit -m "Add unit tests for CredentialRepository"
```

---

### Task 6: CredentialManagementTest

**Files:**
- Create: `Test/Unit/Model/CredentialManagementTest.php`

**Traits used:** MocksCredentialRepositoryTrait

**Tests (8):**
- `testListCredentialsDelegatesToRepository` — calls getByCustomerId, returns result
- `testDeleteCredentialByOwner` — getById returns credential with matching customerId, deletes, dispatches event
- `testDeleteCredentialNotOwner` — credential customerId != provided, expect AuthorizationException
- `testRenameCredentialSuccess` — validates name, loads credential, asserts ownership, sets name, saves
- `testRenameCredentialNotOwner` — expect AuthorizationException
- `testValidateFriendlyNameEmpty` — trim('  ') = '', expect LocalizedException
- `testValidateFriendlyNameTooLong` — 256 chars, expect LocalizedException
- `testValidateFriendlyNameWithXssChars` — contains '<', expect LocalizedException

**Mocks:** `CredentialRepositoryInterface` (via trait), `EventManager`

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/CredentialManagementTest.php
git commit -m "Add unit tests for CredentialManagement"
```

---

### Task 7: PasskeyTokenServiceTest

**Files:**
- Create: `Test/Unit/Model/PasskeyTokenServiceTest.php`

**Traits used:** None

**Tests (2):**
- `testCreateTokenForCustomer` — verify CustomUserContextFactory::create called with userId + USER_TYPE_CUSTOMER, TokenManager::create called, returns token string
- `testCreateTokenPassesCorrectUserType` — verify userType = UserContextInterface::USER_TYPE_CUSTOMER

**Mocks:** `TokenManager`, `CustomUserContextFactory`

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/PasskeyTokenServiceTest.php
git commit -m "Add unit tests for PasskeyTokenService"
```

---

### Task 8: UserHandleGeneratorTest

**Files:**
- Create: `Test/Unit/Model/UserHandleGeneratorTest.php`

**Traits used:** None

**Tests (3):**
- `testGetOrGenerateReturnsExistingHandle` — collection first item has ID, returns its user_handle
- `testGetOrGenerateCreatesNewHandle` — collection first item has no ID, returns base64 string (44 chars for 32 bytes)
- `testGetOrGenerateNewHandleIsBase64` — verify return value is valid base64

**Mocks:** `CollectionFactory` (returns Collection mock), Collection mock (returns model mock via getFirstItem)

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/UserHandleGeneratorTest.php
git commit -m "Add unit tests for UserHandleGenerator"
```

---

### Task 9: WebAuthn/SerializerFactoryTest

**Files:**
- Create: `Test/Unit/Model/WebAuthn/SerializerFactoryTest.php`

**Traits used:** None

**Tests (2):**
- `testGetReturnsSameInstance` — call get() twice, assertSame
- `testGetReturnsSerializerInterface` — assertInstanceOf SerializerInterface

**Note:** This test cannot easily mock the `new BaseSerializerFactory(...)` call inside `get()`. Since BaseSerializerFactory is a third-party class, test at integration level by verifying the return type and singleton behavior. The `AttestationStatementSupportManager` can be a real instance (it has no constructor deps that are hard to satisfy) or mocked.

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/WebAuthn/SerializerFactoryTest.php
git commit -m "Add unit tests for WebAuthn SerializerFactory"
```

---

### Task 10: WebAuthn/CeremonyStepManagerProviderTest

**Files:**
- Create: `Test/Unit/Model/WebAuthn/CeremonyStepManagerProviderTest.php`

**Traits used:** MocksConfigTrait

**Tests (2):**
- `testGetCreationCeremonyReturnsCeremonyStepManager` — config returns allowed origins, assertInstanceOf CeremonyStepManager
- `testGetRequestCeremonyReturnsCeremonyStepManager` — same, for request ceremony

**Note:** Similar to SerializerFactory, this uses `new CeremonyStepManagerFactory()` internally. Test by verifying the return type. Config mock needs `getAllowedOrigins()` returning `['https://example.com']`. `AttestationStatementSupportManager` can be a real instance.

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/WebAuthn/CeremonyStepManagerProviderTest.php
git commit -m "Add unit tests for CeremonyStepManagerProvider"
```

---

### Task 11: Authentication/OptionsGeneratorTest

**Files:**
- Create: `Test/Unit/Model/Authentication/OptionsGeneratorTest.php`

**Traits used:** MocksConfigTrait, MocksCredentialRepositoryTrait, MocksChallengeManagerTrait, MocksSerializerFactoryTrait

**Tests (8):**
- `testGenerateThrowsWhenDisabled` — config->isEnabled() returns false, expect LocalizedException
- `testGenerateThrowsWhenRateLimited` — rateLimiter->checkOptionsRate throws LocalizedException
- `testGenerateWithEmailCustomerFound` — customerRepository returns customer, credentialRepository returns credentials, verify allowCredentials built
- `testGenerateWithEmailCustomerNotFound` — customerRepository throws NoSuchEntityException, still returns valid JSON (anti-enumeration)
- `testGenerateWithoutEmail` — email=null, allowCredentials empty
- `testGenerateCreatesChallengeRecord` — verify challengeManager->create called with TYPE_AUTHENTICATION
- `testGenerateReturnsJsonWithChallengeToken` — verify output JSON contains challengeToken key
- `testGenerateMultipleCredentials` — 2 credentials, verify serialized options include both

**Mocks:** Config (trait), CredentialRepository (trait), ChallengeManager (trait), SerializerFactory (trait), CustomerRepositoryInterface, StoreManagerInterface, Json (Magento serializer), RateLimiter

**Key implementation note:** The serializer mock must handle `serialize()` calls. Mock it to return a JSON string. The `Json` mock's `unserialize` returns an array, `serialize` returns a JSON string. The `StoreManagerInterface` mock needs to provide websiteId.

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/Authentication/OptionsGeneratorTest.php
git commit -m "Add unit tests for Authentication OptionsGenerator"
```

---

### Task 12: Authentication/VerifierTest

**Files:**
- Create: `Test/Unit/Model/Authentication/VerifierTest.php`

**Traits used:** MocksConfigTrait, MocksChallengeManagerTrait, MocksSerializerFactoryTrait, MocksCredentialRepositoryTrait, MocksLoggerTrait

**Tests (10):**
- `testVerifyThrowsWhenDisabled` — config disabled, expect LocalizedException
- `testVerifyThrowsOnInvalidChallengeToken` — challengeManager->consume throws, expect LocalizedException
- `testVerifyThrowsOnInvalidResponseType` — deserialized credential response is not AuthenticatorAssertionResponse, expect LocalizedException
- `testVerifyThrowsWhenCredentialNotFound` — credentialRepository->getByCredentialId throws NoSuchEntityException, dispatches failure event
- `testVerifyThrowsWhenValidatorFails` — validator->check throws, dispatches failure event
- `testVerifyWarnsOnSignCountDecrease` — updatedSource->counter=1, stored signCount=5, logger->warning called but auth succeeds
- `testVerifySucceedsWhenCredentialUpdateFails` — credentialRepository->save throws, logger->error called, still returns result
- `testVerifyThrowsWhenTokenCreationFails` — tokenService->createTokenForCustomer throws, expect LocalizedException
- `testVerifySuccessful` — full happy path: updates counter, dispatches success event, returns AuthenticationResult with customerId + token
- `testVerifyNoWarningWhenBothSignCountsZero` — counter=0, storedCount=0, no warning logged

**Mocks:** Config (trait), ChallengeManager (trait), SerializerFactory (trait), CredentialRepository (trait), Logger (trait), CeremonyStepManagerProvider, PasskeyTokenService, AuthenticationResultInterfaceFactory, EventManager, DateTime

**Key implementation note:** The serializer mock must deserialize to return mock `PublicKeyCredentialRequestOptions` and `PublicKeyCredential` objects. The `PublicKeyCredential` mock needs `response` property (AuthenticatorAssertionResponse or not) and `rawId` property. Since these are webauthn-lib value objects, test by mocking the serializer's deserialize calls. The validator is created via static `AuthenticatorAssertionResponseValidator::create()` — this is hard to mock directly. For the validator tests, we need to either: (a) mock the CeremonyStepManager to make the real validator work, or (b) test only up to the validator call and test error paths. Given the complexity of mocking the full WebAuthn chain, focus on: disabled, invalid challenge, invalid response type, credential not found, sign count checks, update failures, token creation. For the validator success/failure paths, verify the correct mocks are called and exceptions are caught/re-thrown. The `testVerifyThrowsWhenValidatorFails` and `testVerifySuccessful` tests will need to structure the mocks carefully — the serializer returns properly-typed objects, and we verify the behavior around the validator call (the validator itself exercises real crypto which is integration-level).

**Pragmatic approach for validator tests:** Since `AuthenticatorAssertionResponseValidator::create()` is a static factory we can't mock, and the validator performs real crypto operations, the `testVerifySuccessful` and `testVerifyThrowsWhenValidatorFails` tests should verify behavior *around* the validator. For `testVerifyThrowsWhenValidatorFails`, we can make the deserialized PublicKeyCredential have a response that IS an AuthenticatorAssertionResponse (to pass the type check) but will fail validation (since mocked data won't have valid signatures). This will naturally trigger the catch block. For `testVerifySuccessful`, we would need real WebAuthn data — this is better suited for integration tests. Skip this test case and add a comment noting it requires integration-level testing.

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/Authentication/VerifierTest.php
git commit -m "Add unit tests for Authentication Verifier"
```

---

### Task 13: Registration/OptionsGeneratorTest

**Files:**
- Create: `Test/Unit/Model/Registration/OptionsGeneratorTest.php`

**Traits used:** MocksConfigTrait, MocksCredentialRepositoryTrait, MocksChallengeManagerTrait, MocksSerializerFactoryTrait

**Tests (8):**
- `testGenerateThrowsWhenDisabled` — config disabled, expect LocalizedException
- `testGenerateThrowsWhenRateLimited` — rateLimiter throws
- `testGenerateThrowsWhenMaxCredentialsReached` — countByCustomerId returns 10, maxCredentials=10, expect LocalizedException
- `testGenerateSucceedsUnderMaxCredentials` — count=9, max=10, proceeds
- `testGenerateExcludesExistingCredentials` — 2 existing credentials, verify serialized
- `testGenerateCreatesChallengeWithCustomerId` — verify challengeManager->create called with TYPE_REGISTRATION and customerId
- `testGenerateReturnsJsonWithChallengeToken` — output contains challengeToken
- `testGenerateCallsUserHandleGenerator` — verify userHandleGenerator->getOrGenerate called with customerId

**Mocks:** Config (trait), CredentialRepository (trait), ChallengeManager (trait), SerializerFactory (trait), CustomerRepositoryInterface, UserHandleGenerator, Json, RateLimiter

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/Registration/OptionsGeneratorTest.php
git commit -m "Add unit tests for Registration OptionsGenerator"
```

---

### Task 14: Registration/VerifierTest

**Files:**
- Create: `Test/Unit/Model/Registration/VerifierTest.php`

**Traits used:** MocksConfigTrait, MocksChallengeManagerTrait, MocksSerializerFactoryTrait, MocksCredentialRepositoryTrait, MocksLoggerTrait

**Tests (10):**
- `testVerifyThrowsWhenDisabled`
- `testVerifyAcceptsNullFriendlyName` — friendlyName=null, no validation exception
- `testVerifyConvertsEmptyFriendlyNameToNull` — friendlyName='  ', trimmed='', set to null
- `testVerifyThrowsOnFriendlyNameTooLong` — 256 chars, expect LocalizedException
- `testVerifyThrowsOnFriendlyNameWithXss` — contains '<', expect LocalizedException
- `testVerifyThrowsOnInvalidChallengeToken` — consume throws
- `testVerifyThrowsOnInvalidResponseType` — not AuthenticatorAttestationResponse
- `testVerifyThrowsOnMaxCredentialsRaceCondition` — after validation, countByCustomerId >= max, expect LocalizedException
- `testVerifyDispatchesEventOnValidationFailure` — validator throws, event dispatched with customer_id + reason
- `testVerifyFriendlyNameExactly255Chars` — should pass validation

**Same pragmatic approach as Auth Verifier:** Skip full happy path that requires real crypto. Test all paths before and after the validator call.

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Model/Registration/VerifierTest.php
git commit -m "Add unit tests for Registration Verifier"
```

---

### Task 15: Controller/Registration/OptionsTest

**Files:**
- Create: `Test/Unit/Controller/Registration/OptionsTest.php`

**Traits used:** MocksCustomerSessionTrait, MocksJsonResultTrait, MocksLoggerTrait

**Tests (5):**
- `testExecuteNotLoggedIn` — session not logged in, expect 401 response
- `testExecuteSuccess` — logged in, registrationOptions->generate returns JSON, expect decoded array in response
- `testExecuteLocalizedException` — generate throws LocalizedException, expect 400 + error message
- `testExecuteGenericException` — generate throws \Exception, expect 400 + generic message + logger->error called
- `testValidateForCsrfWithAjaxHeader` — request has X-Requested-With=XMLHttpRequest, returns true

**Mocks:** RequestInterface, JsonFactory (trait), CustomerSession (trait), RegistrationOptionsInterface, Logger (trait), ResultFactory

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Controller/Registration/OptionsTest.php
git commit -m "Add unit tests for Registration Options controller"
```

---

### Task 16: Controller/Registration/VerifyTest

**Files:**
- Create: `Test/Unit/Controller/Registration/VerifyTest.php`

**Traits used:** MocksCustomerSessionTrait, MocksJsonResultTrait, MocksLoggerTrait

**Tests (5):**
- `testExecuteNotLoggedIn` — 401
- `testExecuteSuccess` — parses JSON body, calls verifier, returns credential data
- `testExecuteLocalizedException` — 400 + message
- `testExecuteGenericException` — 400 + generic + log
- `testValidateForCsrfWithAjaxHeader` — returns true for XMLHttpRequest

**Mocks:** RequestInterface, JsonFactory (trait), CustomerSession (trait), RegistrationVerifierInterface, JsonSerializer, Logger (trait), ResultFactory

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Controller/Registration/VerifyTest.php
git commit -m "Add unit tests for Registration Verify controller"
```

---

### Task 17: Controller/Authentication/OptionsTest

**Files:**
- Create: `Test/Unit/Controller/Authentication/OptionsTest.php`

**Traits used:** MocksJsonResultTrait, MocksLoggerTrait

**Tests (4):**
- `testExecuteSuccess` — parses body, extracts email, calls generate, returns decoded JSON
- `testExecuteWithoutEmail` — body has no email key, passes null to generate
- `testExecuteLocalizedException` — 400 + message
- `testExecuteGenericException` — 400 + generic + log

**Mocks:** RequestInterface, JsonFactory (trait), AuthenticationOptionsInterface, JsonSerializer, Logger (trait)

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Controller/Authentication/OptionsTest.php
git commit -m "Add unit tests for Authentication Options controller"
```

---

### Task 18: Controller/Authentication/VerifyTest

**Files:**
- Create: `Test/Unit/Controller/Authentication/VerifyTest.php`

**Traits used:** MocksCustomerSessionTrait, MocksJsonResultTrait, MocksLoggerTrait

**Tests (7):**
- `testExecuteRateLimited` — rateLimiter->checkVerifyFailRate throws, expect 400 + records failure
- `testExecuteSuccess` — verifier returns result, customer loaded, session set, returns 200
- `testExecuteSuccessClearsCookie` — mage-cache-sessid cookie exists, gets deleted
- `testExecuteSuccessNoCookie` — no mage-cache-sessid cookie, no delete call
- `testExecuteLocalizedException` — 400 + records failure + logs
- `testExecuteGenericException` — 400 + records failure + logs
- `testValidateForCsrfNotImplemented` — this controller does NOT implement CsrfAwareActionInterface (verify)

**Mocks:** RequestInterface (getContent, getClientIp), JsonFactory (trait), AuthenticationVerifierInterface, CustomerRepositoryInterface, CustomerSession (trait), RateLimiter, JsonSerializer, CookieManagerInterface, CookieMetadataFactory, Logger (trait)

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Controller/Authentication/VerifyTest.php
git commit -m "Add unit tests for Authentication Verify controller"
```

---

### Task 19: Controller/Account/IndexTest

**Files:**
- Create: `Test/Unit/Controller/Account/IndexTest.php`

**Traits used:** MocksCustomerSessionTrait

**Tests (2):**
- `testExecuteNotLoggedIn` — redirects to customer/account/login
- `testExecuteLoggedIn` — returns Page result with 'My Passkeys' title

**Mocks:** PageFactory, RedirectFactory, CustomerSession (trait)

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Controller/Account/IndexTest.php
git commit -m "Add unit tests for Account Index controller"
```

---

### Task 20: Controller/Account/DeleteTest

**Files:**
- Create: `Test/Unit/Controller/Account/DeleteTest.php`

**Traits used:** MocksCustomerSessionTrait, MocksJsonResultTrait, MocksLoggerTrait

**Tests (5):**
- `testExecuteNotLoggedIn` — 401
- `testExecuteSuccess` — gets entity_id from request param, calls deleteCredential, returns 200
- `testExecuteLocalizedException` — 400 + message
- `testExecuteGenericException` — 400 + generic + log
- `testValidateForCsrfWithAjaxHeader` — returns true

**Mocks:** RequestInterface, JsonFactory (trait), CustomerSession (trait), CredentialManagementInterface, Logger (trait), ResultFactory

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Controller/Account/DeleteTest.php
git commit -m "Add unit tests for Account Delete controller"
```

---

### Task 21: Controller/Account/RenameTest

**Files:**
- Create: `Test/Unit/Controller/Account/RenameTest.php`

**Traits used:** MocksCustomerSessionTrait, MocksJsonResultTrait, MocksLoggerTrait

**Tests (5):**
- `testExecuteNotLoggedIn` — 401
- `testExecuteSuccess` — parses JSON body, calls renameCredential, returns 200 + friendly_name
- `testExecuteLocalizedException` — 400 + message
- `testExecuteGenericException` — 400 + generic + log
- `testValidateForCsrfWithAjaxHeader` — returns true

**Mocks:** RequestInterface, JsonFactory (trait), CustomerSession (trait), CredentialManagementInterface, JsonSerializer, Logger (trait), ResultFactory

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Controller/Account/RenameTest.php
git commit -m "Add unit tests for Account Rename controller"
```

---

### Task 22: ChallengeCleanupTest

**Files:**
- Create: `Test/Unit/Cron/ChallengeCleanupTest.php`

**Traits used:** MocksLoggerTrait

**Tests (2):**
- `testExecuteNoExpired` — cleanExpired returns 0, logger->info NOT called
- `testExecuteSomeExpired` — cleanExpired returns 5, logger->info called with message containing '5'

**Mocks:** ChallengeManager, Logger (trait)

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Cron/ChallengeCleanupTest.php
git commit -m "Add unit tests for ChallengeCleanup cron"
```

---

### Task 23: PasskeySectionTest

**Files:**
- Create: `Test/Unit/CustomerData/PasskeySectionTest.php`

**Traits used:** MocksConfigTrait, MocksCustomerSessionTrait, MocksCredentialRepositoryTrait

**Tests (5):**
- `testNotLoggedIn` — returns ['show_enrollment_prompt' => false]
- `testFeatureDisabled` — logged in but disabled, returns false
- `testPromptDisabled` — logged in + enabled but prompt off, returns false
- `testEnabledWithPasskeys` — all on + count > 0, returns false
- `testEnabledWithoutPasskeys` — all on + count = 0, returns true

**Step 1: Write test file**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/CustomerData/PasskeySectionTest.php
git commit -m "Add unit tests for PasskeySection customer data"
```

---

### Task 24: Block tests (Passkeys, PasskeyButton, EnrollmentPrompt)

**Files:**
- Create: `Test/Unit/Block/Account/PasskeysTest.php`
- Create: `Test/Unit/Block/Login/PasskeyButtonTest.php`
- Create: `Test/Unit/Block/EnrollmentPromptTest.php`

**PasskeysTest (1 test):**
Traits: MocksCustomerSessionTrait, MocksCredentialRepositoryTrait
- `testGetCredentials` — session returns customerId, repo returns credentials array

**PasskeyButtonTest (1 test):**
Traits: MocksConfigTrait
- `testGetUiMode` — config returns 'preferred', assert 'preferred'

**EnrollmentPromptTest (3 tests):**
Traits: MocksConfigTrait
- `testToHtmlReturnsEmptyWhenDisabled` — isEnabled=false, returns ''
- `testToHtmlReturnsEmptyWhenPromptDisabled` — enabled=true, promptAfterLogin=false, returns ''
- `testToHtmlRendersWhenEnabled` — both true, calls parent (returns non-empty)

**Note for Block tests:** Blocks extend `Template` which requires a `Context` object. Use `$this->createMock(Context::class)` — this is standard in Magento unit tests. For `_toHtml` test, since parent::_toHtml() does template rendering which requires the full framework, use a partial mock or subclass approach: create a test subclass that overrides `parent::_toHtml()` to return a known string, then verify the config checks.

**Step 1: Write all 3 test files**
**Step 2: Run tests, verify pass**
**Step 3: Commit**
```bash
git add Test/Unit/Block/
git commit -m "Add unit tests for PasskeyAuth blocks"
```

---

### Task 25: Final verification

**Step 1: Run full test suite**
```bash
php vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/MageOS/PasskeyAuth/Test/Unit/ --testdox
```
Expected: All ~130 tests pass.

**Step 2: Verify file count**
```bash
find app/code/MageOS/PasskeyAuth/Test/Unit/ -name "*.php" | wc -l
```
Expected: 34 files (27 test classes + 7 traits)

**Step 3: Final commit if any fixes needed**

---

## Summary

| Task | Class(es) | Tests | Traits Used |
|------|-----------|-------|-------------|
| 1 | Traits (7 files) | 0 | — |
| 2 | ConfigTest | 5 | — |
| 3 | RateLimiterTest | 7 | — |
| 4 | ChallengeManagerTest | 10 | — |
| 5 | CredentialRepositoryTest | 15+ | — |
| 6 | CredentialManagementTest | 8 | MocksCredentialRepository |
| 7 | PasskeyTokenServiceTest | 2 | — |
| 8 | UserHandleGeneratorTest | 3 | — |
| 9 | SerializerFactoryTest | 2 | — |
| 10 | CeremonyStepManagerProviderTest | 2 | MocksConfig |
| 11 | Auth/OptionsGeneratorTest | 8 | MocksConfig, MocksCredentialRepository, MocksChallengeManager, MocksSerializerFactory |
| 12 | Auth/VerifierTest | 10 | MocksConfig, MocksChallengeManager, MocksSerializerFactory, MocksCredentialRepository, MocksLogger |
| 13 | Reg/OptionsGeneratorTest | 8 | MocksConfig, MocksCredentialRepository, MocksChallengeManager, MocksSerializerFactory |
| 14 | Reg/VerifierTest | 10 | MocksConfig, MocksChallengeManager, MocksSerializerFactory, MocksCredentialRepository, MocksLogger |
| 15 | Ctrl/Reg/OptionsTest | 5 | MocksCustomerSession, MocksJsonResult, MocksLogger |
| 16 | Ctrl/Reg/VerifyTest | 5 | MocksCustomerSession, MocksJsonResult, MocksLogger |
| 17 | Ctrl/Auth/OptionsTest | 4 | MocksJsonResult, MocksLogger |
| 18 | Ctrl/Auth/VerifyTest | 7 | MocksCustomerSession, MocksJsonResult, MocksLogger |
| 19 | Ctrl/Acct/IndexTest | 2 | MocksCustomerSession |
| 20 | Ctrl/Acct/DeleteTest | 5 | MocksCustomerSession, MocksJsonResult, MocksLogger |
| 21 | Ctrl/Acct/RenameTest | 5 | MocksCustomerSession, MocksJsonResult, MocksLogger |
| 22 | ChallengeCleanupTest | 2 | MocksLogger |
| 23 | PasskeySectionTest | 5 | MocksConfig, MocksCustomerSession, MocksCredentialRepository |
| 24 | Block tests (3 files) | 5 | MocksCustomerSession, MocksCredentialRepository, MocksConfig |
| 25 | Final verification | 0 | — |
| **Total** | **34 files** | **~130** | |
