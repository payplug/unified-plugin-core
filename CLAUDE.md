# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

`payplug/unified-plugin-core` is a PHP library providing core foundations shared across Payplug
e-commerce plugins (e.g. PrestaShop). Beyond the scaffolding — composer manifest, PSR-4 directory
skeleton, static analysis, code style, git hooks, test harness, CI, and a Dockerized dev
environment — the library now provides: a domain exception hierarchy under `src/Exceptions/`;
six utility classes under `src/Utilities/Helpers/` (`AmountHelper`, dependency-free; `PhoneHelper`,
backed by `giggsey/libphonenumber-for-php`, the library's first real runtime dependency — see
"Constraints to preserve" for what that changed; `PkceHelper`, dependency-free; `ExecCodeMapper`,
dependency-free, maps a Payplug execCode to a `PaymentOutcome`; `WebhookNotificationHelper`,
dependency-free, verifies and parses an asynchronous payment notification; and `Assert`,
dependency-free, shared empty/negative/positive field checks reused by `CommonFieldsDtoValidator`/
`OperationData`/`TokenOutput`); two durable,
direction-agnostic value types under `src/DataValues/` (`PaymentOutcome`, payment-result constants;
`OperationData`, the validating persistence value object `IPaymentRepository` will work with);
three internally-produced value objects under `src/Output/` (`TokenOutput`, validating OAuth2 token
value object; `AuthorizationRequestOutput`, unvalidated PKCE redirect output; `HostedPaymentOutput`,
unvalidated hosted-payment-creation output); the 8 core interfaces under `src/Contracts/`
(`ILogger`, `IConfigurationRepository`, `IPaymentRepository`, `IOrderStateMutator`, `ILock`,
`ITokenCache`, `IOAuthHttpClient`, `IUnifiedApiHttpClient`) that define the boundary between UPC
and each consuming CMS plugin; `src/Auth/` (`OAuth2Client`, `TokenManager`) implementing the
OAuth2/PKCE and client-credentials flows against the identity provider; `src/Services/`
(`AbstractUnifiedApiService`, `UnifiedApiPaymentService`, `UnifiedApiHostedPaymentService`) using
that JWT to call the Unified API to fetch a payment (PRE-3576) and, as of PRE-3587, to
create/confirm one from a hosted-fields token, producing a `HostedPaymentOutput`; and two more
categories: `src/Dto/` (`HostedFieldDto`, that create-call's caller-built input, composing
`CommonFieldsDto`/`BrowserDto`/`CustomerDto`) and `src/Validators/` (`HostedFieldDtoValidator` and
`CommonFieldsDtoValidator`, which check `HostedFieldDto` and `CommonFieldsDto` respectively before
use).

## Commands

All commands run inside a `unified-plugin-core-dev` Docker image (PHP 7.4-cli + Composer, matching
the CI quality baseline) via a bind mount, so no local PHP/Composer install is required — just a
running Docker daemon. The image builds automatically the first time any target runs.

- `make install` — `composer install` inside the container (also runs
  `vendor/bin/captainhook install --force` via Composer's `post-install-cmd`)
- `make test` — run the unit PHPUnit suite (`vendor/bin/phpunit --testsuite=unit`)
- `make test-integration` — run the integration PHPUnit suite (`vendor/bin/phpunit
  --testsuite=integration`); first real test landed in PRE-3576
  (`tests/Integration/UnifiedApiPaymentServiceTest.php`, requires VPN access) — copy
  `.env.example` to `.env` and fill in the `UPC_IT_*` values (identity-provider + Unified API
  credentials/URLs); the target passes `--env-file .env` to `docker run` when the file exists, and
  the test itself calls `markTestSkipped()` when any required var is unset
- `make coverage` — run the PHPUnit suite with code coverage (PCOV, installed in the Docker image),
  writing a Clover XML report to `build/logs/clover.xml` (`build/` is gitignored); this is what CI's
  `coverage` job feeds to SonarCloud
- `make stan` — PHPStan level 8 analysis (`phpstan.neon`)
- `make cs-lint` — PHP-CS-Fixer dry-run diff, no changes written
- `make cs-fix` — PHP-CS-Fixer, applies fixes
- `make quality` — `cs-lint` + `stan` + `test` in sequence (mirrors the CI `quality` job)
- `make shell` — interactive `bash` in the dev container; use this to run a single test or any
  other one-off command, e.g. `vendor/bin/phpunit tests/ScaffoldingTest.php` or
  `vendor/bin/phpunit --filter testMethodName`
- `make build` — rebuild the Docker image explicitly (rarely needed; other targets depend on it)
- `make verify-71` — the only target that actually exercises the PHP 7.1 runtime floor.
  Composer itself refuses to run below PHP 7.2.5, and this repo's own `composer.json`
  `require.php` (`>=7.4`) is a build-tooling floor, not a runtime one — so there is no
  meaningful "install under PHP 7.1" for this project. Instead: installs a `--no-dev`
  vendor tree (what actually ships to merchants — dev tooling never bundles into the
  plugin ZIP) into a separate `vendor-nodev/` via `COMPOSER_VENDOR_DIR`, without
  touching the main dev `vendor/`, then boots it under a real `php:7.1-cli` container
  (`Dockerfile.php71-check`, no Composer needed there) and runs `php -l` on `src/`/
  `tests/` plus `scripts/verify-php71-smoke.php` (a plain, 7.1-syntax script — it can't
  use PHPUnit, which itself needs PHP ≥7.3). Run this after touching `composer.json` or
  any dependency version.

## Architecture

- PSR-4 autoload root: `PayplugUnifiedCore\` → `src/`; dev-only autoload root:
  `PayplugUnifiedCore\Tests\` → `tests/`.
- `src/` is organized into top-level categories: `Auth/`, `Contracts/`, `DataValues/`, `Dto/`,
  `Exceptions/`, `Output/`, `Services/`, `Utilities/Helpers/`, `Validators/`. New code should
  generally land under an existing matching category — a new top-level category is for when
  there's a real, growing need for one (as `Dto/`/`Validators/` were split out from
  `Models/`/`Utilities/Helpers/`, and `Models/` itself later replaced entirely by `DataValues/` and
  `Output/`, once distinguishing "durable value with its own lifecycle" from "caller-built input"
  from "internally-produced output" stopped being a distinction worth blurring), not for a single
  one-off class. `Models/` no longer exists as a category — every class that lived there moved to
  `DataValues/` or `Output/` (see those bullets below for which, and why).
- `Exceptions/` holds the domain exception hierarchy: `PayplugException` (base, extends
  `\Exception` directly) and ten subtypes — `RefundAmountException`, `PaymentNotFoundException`,
  `InvalidPhoneNumberException`, `CardOperationException`, `ApiException`,
  `InvalidOperationDataException`, `InvalidTokenException`, `InvalidNotificationException`,
  `InvalidHostedFieldException`, `InvalidCommonFieldsException` — each
  a plain marker class extending `PayplugException` directly, with no custom constructor or
  properties, so CMS plugins can catch specific error types instead of a generic exception. Any
  future addition to this hierarchy should follow the same pattern: one class per file, no PHP
  7.1-incompatible syntax, and a matching test in `tests/Exceptions/` verifying the `instanceof`
  chain and the inherited message/code/previous constructor contract. Because PHPStan level 8
  includes the `phpstan-phpunit` extension, an
  `assertInstanceOf()` check against a statically-provable `extends` relationship needs an inline
  `// @phpstan-ignore-next-line staticMethod.alreadyNarrowedType` comment directly above it (see
  any file in `tests/Exceptions/` for the exact pattern) — the assertion is kept as a regression
  guard, not removed.
- `DataValues/` (formerly folded into `Models/`, split into its own category once the vocabulary
  needed distinguishing from `Dto/`/`Output/` below) holds durable, direction-agnostic value
  types — not the input to one specific call or the output of another, but data with a life of its
  own. `PaymentOutcome` is a non-instantiable constants container (`final class` + private
  `@codeCoverageIgnore`d constructor, same pattern as the `Utilities/Helpers/` classes below)
  holding 6 string constants (`PAID`, `AUTHORIZED`, `CAPTURE_REQUIRED`, `THREE_DS_PENDING`,
  `REFUNDED`, `FAILED`) — a PHP 7.1 stand-in for a PHP 8.1 `enum` — plus `isValid(string $value):
  bool`. `OperationData` is the persistence value object `IPaymentRepository` (PRE-3467, not yet
  implemented) will work with: public properties (`operationId`, `execCode`, `outcome`, `amount`,
  `orderId`, each with a `/** @var */` docblock — PHP 7.1 predates typed properties) set through a
  validating constructor. Per this library's "never trust external I/O" rule, `OperationData`'s
  constructor is the validation boundary — it rejects an empty `operationId`/`execCode`/`orderId`,
  a negative `amount`, or an `outcome` that isn't a `PaymentOutcome` constant, throwing the new
  `InvalidOperationDataException` (6th subtype in the `Exceptions/` hierarchy above). `execCode`
  is typed `string`, not `int`: Payplug's execution-codes documentation describes it as a numeric
  string (e.g. `"4001"`, `"6003"`) from an open-ended, growing catalog, so only non-emptiness is
  validated, not a specific digit pattern. `amount` is `int` centimes, matching
  `AmountHelper::toCents()`'s output convention. **Placement note** (an explicit overlap call, not
  an oversight): `WebhookNotificationHelper::parse()` produces an `OperationData` from a parsed
  webhook payload, which reads as `Output/`-shaped — but that's not its whole story. It's also
  exactly what `IPaymentRepository::save()`/`getByOrderId()`/`getByOperationId()` persist and
  re-fetch: durable state with a life beyond any single call, not a one-off call result. That's
  `DataValues/`'s defining trait, so that's where it stays. Matching tests in `tests/DataValues/`.
- `Output/` holds value objects produced entirely internally by some UPC method rather than built
  by the caller — the opposite direction from `Dto/` below, which the caller builds. Whether the
  constructor itself validates varies by class, unlike a hard category rule: `TokenOutput`
  (PRE-3563, named `Token` before this ticket's `DataValues/`/`Output/` split) is the validating
  value object for a freshly-minted OAuth2 token response (`accessToken`, `expiresIn`, `tokenType`,
  each with a `/** @var */` docblock), constructed only from data that has already crossed UPC's
  external boundary (an OAuth2 token-endpoint response) — its constructor rejects an empty
  `accessToken`/`tokenType` or a non-positive `expiresIn`, throwing the new `InvalidTokenException`
  (7th subtype in the `Exceptions/` hierarchy). `AuthorizationRequestOutput` (PRE-3563, named
  `AuthorizationRequest` before the split) is the output of `OAuth2Client::buildAuthorizationUrl()`
  (`url`, `state`, `codeVerifier`) — unlike `TokenOutput`, its constructor holds no validation at
  all, since it's produced entirely internally by `OAuth2Client` and never itself reflects an
  external response. `HostedPaymentOutput` (PRE-3587, named `HostedPaymentResult` before the split)
  is the output of `UnifiedApiHostedPaymentService::createHostedPayment()` (`status`, `body`,
  `redirectUrl`, `redirectHtml`) — same unvalidated-constructor reasoning as
  `AuthorizationRequestOutput`, since it's produced entirely internally from a Unified API response
  the service has already checked for a 2xx status. `redirectUrl`/`redirectHtml` are the two
  derived 3DS-pending fields, both null on a direct success. Per the Unified API's own 3DS doc
  (advanced-payment-scenarios-and-features/3d-secure-implementation/using-payplugs-3ds-module),
  a pending challenge is signalled by `execCode=0001` alongside a `redirect` object shaped one of
  two ways: `redirect.html` (Base64-encoded, "recommended for web" — decoded here into
  `redirectHtml`, the raw HTML the CMS plugin must inject into its own page; it contains a
  self-submitting form that sends the end user to the bank's challenge page) or `redirect.url` +
  `redirect.postParams` (only when the request set `card.threeDSecure.displayMode=raw` — this
  library only extracts the bare `redirect.url` into `redirectUrl`; `postParams` isn't extracted,
  since nothing requests raw mode yet). Neither field deliberately maps to a `PaymentOutcome`
  constant — that mapping, for the asynchronous webhook/3DS-return confirmation that comes later,
  is PRE-3588's job, not this ticket's. Matching tests in `tests/Output/`.
- `Dto/` is a category of its own — split out from `Models/` once more than one DTO was expected,
  rather than growing `Models/` indefinitely (see the top-level-categories bullet above). Holds
  four classes, all assembled by the CMS plugin itself as input to a payment-creation call, with
  the same unvalidated-constructor reasoning as `AuthorizationRequestOutput`/`HostedPaymentOutput`
  in `Output/` (validation is a separate step — `HostedFieldDtoValidator`/`CommonFieldsDtoValidator`,
  see `Validators/` below — rather than folded into construction). `BrowserDto` (`ip`, `referrer`,
  `userAgent`, all required constructor parameters) and `CustomerDto` (`id`, `email`, both
  required) hold end-user context reusable by any future payment-method DTO, not just
  hosted-fields — real Unified API payloads for raw-card and Apple Pay payments both carry the
  identical `browser`/`customer` shape. Requiring all their fields as constructor parameters
  (rather than a loose array) is what actually enforces the Unified API schema's "all sub-fields
  present together or none" rule now: a partial `BrowserDto`/`CustomerDto` can't be constructed, so
  `HostedFieldDtoValidator` no longer needs to check for that shape at runtime. Both expose
  `toArray(): array` for `HostedFieldDto::createPayloadBody()` to call. `CommonFieldsDto` holds the
  payment-creation fields common to every payment method — `accountId`/`amount`/`currency`/
  `orderId`/`submerchantExternalId` as required constructor parameters (`submerchantExternalId`
  added by PRE-3587 for marketplace/sub-merchant routing), `description`/`capture` (default
  `true`)/`descriptor`/`notificationUrl`/`extraData`/`successUrl`/`cancelUrl` as public properties
  set by direct assignment after construction (`successUrl`/`cancelUrl` added to carry the 3DS/SCA
  challenge's redirect-return URLs — see the `redirect` object in `createPayloadBody()` below) —
  reusable the same way `BrowserDto`/`CustomerDto` are. `HostedFieldDto` composes
  all three (`CommonFieldsDto $common`, `?BrowserDto $browser = null`, `?CustomerDto $customer =
  null`) plus its own two payment-method-specific fields (`hfToken`, `?array $paymentMethod =
  null`) — a 5-parameter constructor, replacing what had grown to 13 parameters directly on
  `HostedFieldDto` and tripped SonarCloud's `php:S107` ("too many parameters") check. `paymentMethod`
  stays a plain array rather than its own DTO: for hosted-fields it's only ever `details`
  (fullName/selectedBrand/validityDate), and a real `PaymentMethodDto` would need to know what a
  raw-card flow needs too — a shape that isn't known yet, so it isn't guessed at. `createPayloadBody():
  array` builds the exact Unified API request body this DTO describes, reading `$this->common->*`
  directly for the fields `CommonFieldsDto` owns (they land in different shapes in the body —
  `accountId` nests under `account.id`, `successUrl`/`cancelUrl` nest under a conditional
  `redirect` object, the rest are flat) and calling `->toArray()` on
  `browser`/`customer` when non-null; every field the body needs still lives across these four
  DTOs, so `UnifiedApiHostedPaymentService` (see `Services/` below) has nothing left to construct
  itself. Matching tests in `tests/Dto/`.
- `Contracts/` holds the 8 interfaces that define the boundary between UPC and each consuming CMS
  plugin (first real consumer: UHF/Sylius) — designed around what a CMS needs to provide, not
  around the not-yet-built Unified API's shape, so they survive that later transition intact. All
  8 are pure interfaces (no logic, nothing for PHPUnit to exercise — PHPStan level 8 verifies
  signatures statically instead), PHP 7.1-compatible, each with a class-level docblock sketching
  one Sylius and one WooCommerce implementation (illustrative only, not shipped code) instead of
  the single-call-site `<code>` example used by `Utilities/Helpers/`. `ILogger` (`debug`/`info`/
  `error`, each `(string $message, array $context = []): void`) is a structured logging sink
  decoupled from any CMS's native logger. `IConfigurationRepository` (`get(string $key): ?string`,
  `set(string $key, string $value): void`, `getClientId()`, `getClientSecret()`,
  `getPublicKeyId()`, `getPublicKeyValue()`, all `: string`) sources OAuth2 credentials and Hosted
  Fields public key material from each CMS's own settings storage. `IPaymentRepository`
  (`getByOrderId`/`getByOperationId(string): OperationData`, both `@throws
  PaymentNotFoundException` — the first user of that exception subtype, joined by
  `Services/UnifiedApiPaymentService` mapping a Unified API 404 onto it — plus
  `save(OperationData): void`, `markTreated(string): void`, `isTreated(string): bool`) persists
  `OperationData` and tracks webhook idempotency. `IOrderStateMutator`
  (`apply(string $orderId, string $outcome): void`) applies a `PaymentOutcome` to the CMS-native
  order — takes the order by ID rather than by CMS-native object, since Sylius's `OrderInterface`
  and WooCommerce's `WC_Order` share no common type to hint against, so each implementation loads
  its own native order internally. `ILock` (`acquire(string $key, int $ttlSeconds): bool`,
  `release(string $key): void`) is a per-operation mutex preventing a retried webhook from being
  processed concurrently with itself; `acquire()` returns `false` on contention rather than
  throwing, since a webhook retry hitting an already-held lock is routine, not exceptional.
  `ITokenCache` (`get(string $key): ?string`, `set(string $key, string $value, int $ttlSeconds):
  void`, `delete(string $key): void`) caches the OAuth2 JWT UPC will use against the future
  Unified API — the TTL/renewal timing is the caller's concern, this contract just stores a value
  for whatever TTL it's given. `IOAuthHttpClient` (`post(string $url, array $formParams, array
  $headers = []): array{status: int, body: string}`) is a narrow HTTP contract for OAuth2 token
  exchange only (PRE-3563). `IUnifiedApiHttpClient` (PRE-3576, extended PRE-3587) is a separate
  contract for calling the Unified API — `get(string $url, array $headers = []): array{status: int,
  body: string}` for reading resources (payment retrieval, via `Services/UnifiedApiPaymentService`)
  and `postJson(string $url, array $body, array $headers = []): array{status: int, body: string}`
  for creating them (hosted-fields payment creation, via
  `Services/UnifiedApiHostedPaymentService`) — kept distinct from `IOAuthHttpClient` rather than
  extending it, since token exchange (POST+form-encoded) and Unified API calls (GET/POST+bearer
  token+JSON) are different enough shapes that sharing one contract would blur both. `postJson` is
  named distinctly from `IOAuthHttpClient::post()` (not reusing "post") specifically so a class
  implementing both contracts — as `tests/Integration/Support/CurlHttpClient` does — never has to
  guess which body encoding a single shared method name should apply.
- `Utilities/Helpers/` holds small static utility classes — no CMS calls, no network calls; most
  are also dependency-free, but that's not a hard rule (see `PhoneHelper` below). The first one,
  `AmountHelper`, centralizes float↔centimes amount conversion
  (`toCents(float $amount, int $mode = PHP_ROUND_HALF_UP): int`, `fromCents(int $cents): float`)
  that was previously duplicated with divergent rounding behavior across the sibling CMS plugins
  (notably `ps_round` on the PrestaShop side). Pattern for this category: `final class` with a
  private, `@codeCoverageIgnore`d constructor (blocks instantiation without inflating the coverage
  denominator with an intentionally-empty body — PHP's constructor-visibility check throws before
  the body would ever execute, so a test calling it can never actually cover it) and public static
  methods only, each with a docblock `<code>` example showing a realistic plugin call site; a
  matching test in `tests/Utilities/Helpers/`. `toCents()`'s `$mode` parameter exists specifically
  because PrestaShop is the only sibling CMS that lets merchants configure their own rounding
  algorithm (`PS_ROUND_MODE`, consumed by `Tools::ps_round()`); WooCommerce/Magento 2/Sylius all
  hardcode PHP's default rounding and will simply never pass `$mode`. The mode only changes the
  outcome for genuinely ambiguous inputs landing exactly on a half-cent boundary (e.g. `19.995`) —
  an already-decided 2-decimal amount rounds identically under every mode — so callers should pass
  their own resolved rounding preference in rather than pre-rounding themselves. Because
  PHPStan's core stubs constrain `round()`'s `$mode` parameter to the literal type `1|2|3|4` (the
  `PHP_ROUND_HALF_*` constants), `toCents()`'s own `$mode` parameter needs a matching
  `@param 1|2|3|4 $mode` docblock annotation — a plain `@param int $mode` fails `make stan`; watch
  for an IDE/formatter silently "simplifying" it back.
- `PhoneHelper` (same `final class` + private-constructor pattern as `AmountHelper`) centralizes
  phone number normalization — `toE164(string $phone, string $countryCode): string` and
  `isMobile(string $phone, string $countryCode): bool` — previously duplicated between plugins (PS
  `PhoneHelper.php`, WC's `PayplugAddressData` parsing), backed by `giggsey/libphonenumber-for-php`.
  `$countryCode` is a 2-letter ISO 3166-1 alpha-2 region code (the UK's is `GB`, not `UK`). Both
  methods share a private `parse()` helper; any unparseable/invalid input throws
  `InvalidPhoneNumberException` from both. This is the library's first helper with a real runtime
  dependency — see "Constraints to preserve" below for the PHP 7.1 floor implications that came
  with it, and `make verify-71` for how that floor is actually verified.
- `PkceHelper` (PRE-3563, same `final class` + private-constructor pattern as `AmountHelper`/
  `PhoneHelper`) generates the PKCE material for the authorization-code flow —
  `generateCodeVerifier(): string` (RFC 7636 §4.1, cryptographically random via `random_bytes`),
  `deriveCodeChallenge(string $codeVerifier): string` (S256 only — the `plain` method isn't
  supported), and `generateState(): string` (CSRF guard). Matching test in
  `tests/Utilities/Helpers/`, including a golden-value assertion against RFC 7636 Appendix B's own
  worked example.
- `ExecCodeMapper` (PRE-3588, same `final class` + private-constructor pattern as the helpers
  above) maps a Payplug `execCode` to `PaymentOutcome::PAID` (only for `"0000"`) or
  `PaymentOutcome::FAILED` (everything else) via `toPaymentOutcome(string $execCode): string`.
  Deliberately minimal: the platform's execCode catalog is a cross-processor internal error
  taxonomy far more detailed than any merchant-facing outcome needs, and finer-grained outcomes
  (`AUTHORIZED`/`CAPTURE_REQUIRED`) aren't derivable from `execCode` alone with the webhook fields
  currently documented. Shared between the synchronous payment-creation flow (PRE-3587) and the
  asynchronous webhook confirmation flow (`WebhookNotificationHelper`, below), so this mapping
  decision lives in exactly one place. Matching test in `tests/Utilities/Helpers/`.
- `WebhookNotificationHelper` (PRE-3588, same pattern) parses and validates an asynchronous
  "Payment Operation" notification (webhook/3DS confirmation), independently of the CMS that
  receives the HTTP request. `verifySignature(array $headers, string $expectedAuthorizationHeader): void`
  does a case-insensitive lookup of an `Authorization` key in `$headers` and a constant-time
  (`hash_equals()`) comparison against `$expectedAuthorizationHeader` — the platform's webhook
  receiver has no HMAC/signature-over-body scheme, only a shared secret configured at
  webhook-creation time and echoed back in that header (Basic or Bearer, merchant's choice); it
  throws `InvalidNotificationException` if the header is absent or doesn't match.
  `parse(array $headers, string $rawBody, string $expectedAuthorizationHeader): OperationData`
  calls `verifySignature()`, decodes `$rawBody` as JSON, validates presence of `id`/`execCode`/
  `orderId`/`amount`, maps the outcome via `ExecCodeMapper`, and returns `new OperationData(...)`
  — reusing the existing value object rather than introducing a new one, since its shape matches
  exactly. `InvalidOperationDataException` from that constructor is wrapped into
  `InvalidNotificationException` (same pattern as `OAuth2Client::requestToken()` wrapping
  `InvalidTokenException` into `ApiException`). `parse()` never itself returns
  `PaymentOutcome::THREE_DS_PENDING`: the platform's execCode documentation states the transient
  in-flight codes are never emitted as an asynchronous notification, so a fired webhook always
  carries a final code. A CMS controller resolves a previously `THREE_DS_PENDING` `OperationData`
  (set by PRE-3587's synchronous flow) to its final state by calling `parse()` on the webhook and
  persisting the `OperationData` it returns via `IPaymentRepository`/`IOrderStateMutator` — this is
  the "interface exploitable par un contrôleur CMS" this ticket exists to provide; no new
  `Contract` was needed since this logic isn't implemented differently per CMS. Matching test in
  `tests/Utilities/Helpers/`.
- `Assert` (same `final class` + private-constructor pattern as the helpers above) holds the
  "field must not be empty" / "must not be negative" / "must be positive" checks that
  `CommonFieldsDtoValidator`, `OperationData`'s constructor, and `TokenOutput`'s constructor had
  each independently hand-rolled with only the field name, comparison, and exception class
  differing — `notEmpty(string $value, string $fieldName, string $exceptionClass): void`,
  `notNegative(int $value, string $fieldName, string $exceptionClass): void`,
  `positive(int $value, string $fieldName, string $exceptionClass): void`. `$exceptionClass` is a
  `class-string<PayplugException>` supplied by the caller (`new $exceptionClass($message)`) rather
  than Assert throwing one exception type of its own, since each of the three existing call sites
  needs its own already-established exception type
  (`InvalidCommonFieldsException`/`InvalidOperationDataException`/`InvalidTokenException`) and
  none of them could be re-parented onto a shared one without changing every consumer's catch
  behavior. Matching test in `tests/Utilities/Helpers/`.
- `Validators/` is a category of its own — split out from `Utilities/Helpers/` once more than one
  validator was expected, rather than growing that category indefinitely (see the
  top-level-categories bullet above). Holds two classes (same `final class` + private-constructor
  pattern as the `Utilities/Helpers/` classes above). `CommonFieldsDtoValidator::validate(
  CommonFieldsDto $dto): void` checks `accountId`/`orderId`/`currency` non-empty and `amount` not
  negative, throwing the new `InvalidCommonFieldsException` (10th subtype in the `Exceptions/`
  hierarchy) on the first problem found — reusable by any future payment-method DTO that composes a
  `CommonFieldsDto`, not just `HostedFieldDto`. `HostedFieldDtoValidator::validate(HostedFieldDto
  $dto): void` delegates to it (catching `InvalidCommonFieldsException` and wrapping it into
  `InvalidHostedFieldException`, so `createHostedPayment()`'s existing `@throws
  InvalidHostedFieldException` contract for callers doesn't change), then checks `hfToken`
  non-empty — the one thing still genuinely hosted-fields-specific. The wrap reuses the original
  message verbatim (e.g. `"accountId must not be empty."`) rather than a generic one, so a caller
  catching `InvalidHostedFieldException` sees the same level of specificity regardless of which
  check failed; the original exception is still attached as the previous exception either way. The
  old `browser`/`customer` "missing a required sub-field" checks are gone entirely: now that
  `HostedFieldDto::$browser`/`$customer` are typed `BrowserDto`/`CustomerDto` objects rather than
  loose arrays, a partial one can't be constructed in the first place — the DTO's own constructor
  enforces the Unified API schema's "all sub-fields present together" rule instead of a runtime
  check. Matching tests in `tests/Validators/`.
- `src/Auth/` (PRE-3563) holds the two classes with real OAuth2 logic — everything else this
  ticket adds (`IOAuthHttpClient`, `PkceHelper`, `TokenOutput`, `AuthorizationRequestOutput`, both
  renamed from `Token`/`AuthorizationRequest` when `Models/` was later split into `DataValues/`/
  `Output/`) is a contract, helper, or value object slotting into an existing category.
  `OAuth2Client` (`final class`) is pure token mechanics against the identity provider, with no
  caching of its own: `buildAuthorizationUrl(string $clientId): AuthorizationRequestOutput`
  generates the PKCE verifier/challenge/state via `PkceHelper`
  and returns the redirect URL without calling `header()` itself (the caller performs the actual
  redirect); `exchangeAuthorizationCode(string $clientId, string $code, string $codeVerifier):
  TokenOutput` and `getClientCredentialsToken(string $clientId, string $clientSecret):
  TokenOutput` both POST
  via the injected `IOAuthHttpClient` and throw the existing `ApiException` on a non-2xx response
  or a malformed body. The constructor takes `IOAuthHttpClient $httpClient, string $baseUrl,
  string $redirectUri, string $scope, string $audience` — only the two *resource paths*
  (`/oauth2/auth`, `/oauth2/token`) are `private const`s on the class; `$baseUrl` is a plain
  constructor argument, replacing the legacy SDK's pattern of a hardcoded base-URL constant
  swapped via a CI `sed` command for the `-qa` environment. `TokenManager` (`final class`) wraps
  `OAuth2Client`'s client-credentials flow with `ITokenCache`, for background API calls:
  `getValidToken(string $clientId, string $clientSecret): string` checks the cache (key:
  `'upc_oauth_token:' . $clientId`), and on a miss calls
  `OAuth2Client::getClientCredentialsToken()` and caches the resulting access-token string with a
  TTL shortened by a fixed 60-second renewal margin (`max(1, expiresIn - 60)`) — a request should
  never receive a token that's about to expire mid-flight. `getValidToken()` returns the bare
  access-token `string`, not the full `TokenOutput` object: `ITokenCache` only stores a single
  string value, so round-tripping `TokenOutput`'s other fields through the cache would mean either serializing
  them (leaving a misleading `expiresIn` that reflects the original grant, not remaining time — the
  cache's own shortened TTL is what actually enforces freshness) or not bothering, since
  `tokenType` is always `"Bearer"` for this flow anyway.
  `refreshToken(string $clientId, string $clientSecret): string` is the companion escape hatch for
  a caller holding a token the API just rejected: the 60-second renewal margin only covers a token
  aging out, not one invalidated *early* (rotated client secret, revoked grant, changed
  permissions, or clock skew wider than the margin), and without it a single poisoned cache entry
  would fail every call until its TTL runs out. It calls `ITokenCache::delete()` — the first user
  of that contract method — *before* attempting the mint rather than just overwriting afterwards,
  so a failed mint still leaves the rejected token gone instead of replaying it; both methods share
  a private `mintAndCache()`.
- `src/Services/` (PRE-3576, extended PRE-3587) holds application services that use `Auth/`'s JWT
  to call the Unified API. `AbstractUnifiedApiService` (`abstract class`, PRE-3587) holds the
  mechanics shared by every concrete service: resolving a client-credentials JWT via the injected
  `TokenManager`/`IUnifiedApiHttpClient`/`baseUrl`/`clientId`/`clientSecret` (all `protected`
  constructor-set properties), a protected `sendGet(string $url)` and
  `sendPostJson(string $url, array $body)`, and the 401-retry-then-normalize logic shared by both —
  extracted from `UnifiedApiPaymentService` once it got a sibling
  (`UnifiedApiHostedPaymentService`), per this file's own prior instruction not to duplicate the
  pattern a third time. `UnifiedApiPaymentService` (`final class`, extends
  `AbstractUnifiedApiService`) exposes `getPayment(string $paymentId): array{status: int, body:
  string}` — GETs `<baseUrl>/payments/<paymentId>` and returns the raw HTTP response. It does not
  parse the response into a value object: the full payment data model returned by the Unified API
  is explicitly out of scope for this ticket, deferred to a future one. `client_id`/`client_secret`/
  `baseUrl` are plain constructor arguments (matching `OAuth2Client`'s existing pattern) rather than
  sourced from `IConfigurationRepository`, so `getPayment()` itself takes only `$paymentId`. A
  non-2xx HTTP status or a malformed `IUnifiedApiHttpClient` response both throw the existing
  `ApiException`, mirroring `OAuth2Client::requestToken()`'s exact precedent rather than introducing
  a new exception subtype or an error-object return type — with one exception: a **404** throws
  `PaymentNotFoundException` instead, since "this payment doesn't exist" is a terminal outcome a
  plugin handles differently from "the API is broken". Note that subtype is a *sibling* of
  `ApiException` (both extend `PayplugException` directly), so a consumer catching `ApiException`
  alone will **not** catch a missing payment; that's deliberate, and
  `testPaymentNotFoundIsNotCaughtAsAnApiException` guards it, because re-parenting the exception
  later would silently change every consumer's catch behavior. Both exception types carry the HTTP
  status as their exception **code** (`getCode()`), so callers can branch on 404-vs-503 without
  parsing the message; the code is `0` only when the client's response shape was unusable and no
  status was ever received. `OAuth2Client::requestToken()` was updated to match. A 401 is the
  other special case: it retries once via `TokenManager::refreshToken()` (see the `Auth/` bullet
  above) before throwing, since a cached JWT can be rejected while still inside its cache TTL. The
  retry is deliberately bounded at one — a 401 on a token minted seconds ago is a
  credentials/permissions problem that retrying cannot fix — and the non-2xx check runs once, after
  the possible retry (this retry-then-normalize loop now lives in `AbstractUnifiedApiService`, not
  on `UnifiedApiPaymentService` itself). Matching unit test in `tests/Services/` (mocked
  `IUnifiedApiHttpClient`), plus the first genuine `tests/Integration/` test — a real curl-based
  `IOAuthHttpClient`/`IUnifiedApiHttpClient` double drives an actual call against a staging fixture
  payment, gated behind `UPC_IT_*` environment variables (see `.env.example`) and skipped when
  unset, since the target environment is VPN-only and can never run in CI.
  `UnifiedApiOperationService` (`final class`, extends `AbstractUnifiedApiService`) is
  `UnifiedApiPaymentService`'s sibling for the operation resource rather than a method on it: an
  operation (one processing event — a payment, a capture, a refund — identified by an id drawn
  from a payment's own `operationIds` array) is a distinct resource from the payment it belongs
  to, with its own endpoint and its own not-found case. `getOperation(string $operationId):
  array{status: int, body: string}` GETs `<baseUrl>/processing-operations/operations/<operationId>`
  and returns the raw HTTP response, unparsed, for the same reason `getPayment()` does. A 404
  throws `OperationNotFoundException` — a sibling of `ApiException`, not a subtype, mirroring
  `PaymentNotFoundException`'s exact precedent — everything else (401-retry, non-2xx handling,
  malformed-response handling) is inherited from `AbstractUnifiedApiService` unchanged. Notably,
  an operation's representation carries `transaction.status.execCode` — the same execCode
  vocabulary `ExecCodeMapper` already maps from the webhook and payment-creation flows — unlike
  the payment representation itself, which does not surface an execCode at all; a caller polling
  for a payment's outcome (e.g. a CMS plugin's fallback for a delayed webhook) fetches the
  operation, not the payment.
  `UnifiedApiHostedPaymentService` (`final class`, extends `AbstractUnifiedApiService`, PRE-3587,
  refactored to take a `HostedFieldDto` instead of 11 positional parameters) is the create-side
  sibling: `createHostedPayment(HostedFieldDto $dto): HostedPaymentOutput` POSTs `<baseUrl>/payments`
  via `sendPostJson($url, $dto->createPayloadBody())`, after `HostedFieldDtoValidator::validate($dto)`
  runs as the method's first line — see `Validators/` above for what it checks and why. The
  request body itself is built entirely by `HostedFieldDto::createPayloadBody()` (`Dto/`, above),
  not by the service — every field that body needs lives across `HostedFieldDto` and the
  `CommonFieldsDto`/`BrowserDto`/`CustomerDto` it composes (see the `Dto/` bullet above for the
  current shape), so the service has nothing left to construct. `HostedFieldDto` is built by the
  CMS plugin itself and passed in whole rather than as positional
  arguments — the original ticket's own stated signature only covered the first 4 (`hfToken`,
  `amount`, `currency`, `orderId`); the other 7 (`browser`, `customer`, `description`,
  `paymentMethod`, `descriptor`, `notificationUrl`, `extraData`) were added after
  cross-checking the Unified API's own OpenAPI schema (the "server-to-server" gitbook page's prose
  only covers the raw-card variant and doesn't show where `hfToken` goes — a real hosted-fields
  Postman example from the Unified API team confirmed `hfToken` is a top-level body field, not
  nested under `paymentMethod`). Only `account` and `amount` are required per the doc;
  `paymentMethod`, `currency`, `orderId`, `hfToken`, `browser`, `customer`, `description`,
  `descriptor`, `notificationUrl`, `extraData` are all optional — contradicting the ticket's implied
  4-required-parameter shape; the doc is treated as the source of truth over the ticket text.
  `descriptor`/`notificationUrl`/`extraData` were added from a second, separately-supplied summary
  of the same endpoint's full field list, cross-checked against a much larger candidate set
  (recurring/subscriptions, Oney installment `commercialCode`, `transferReason`, etc.) that was
  deliberately **not** added: those are out of this ticket's scope (hosted-fields card payment +
  3DS), and adding them now would be speculative surface area for use cases nobody's asked for yet.
  `metaData` was also excluded, on suspicion it's a summarization artifact/duplicate of `extraData`
  rather than a genuine distinct field — their described purposes were near-identical in that
  summary, unlike every other field pair. Two fields from that same candidate set were added later,
  once an actual consumer needed them: `submerchantExternalId` (PRE-3587, marketplace/sub-merchant
  routing — a required `CommonFieldsDto` constructor parameter, not optional like the fields below)
  and `successUrl`/`cancelUrl` (this fix, nested under a `redirect` object — the 3DS/SCA challenge's
  return-to-merchant URLs; the Unified API payload documents `redirect.successUrl`/
  `redirect.cancelUrl`, confirmed by the requesting ticket owner, not merely inferred). Body:
  `{"account": {"id": $dto->common->accountId}, "amount", "currency", "orderId",
  "submerchantExternalId", "capture": $dto->common->capture, "hfToken"}`, plus `"paymentMethod"`
  (set directly from the DTO's `paymentMethod` property — its shape mirrors the Unified API's own
  nesting exactly, e.g. `['details' => ['fullName' => ..., 'selectedBrand' => ...]]`, rather than
  being reconstructed from a flatter parameter), `"browser"`, `"customer"`, `"description"`,
  `"descriptor"`, `"notificationUrl"`, `"extraData"` — each added only when the corresponding
  property (on `HostedFieldDto` itself, or on the composed `CommonFieldsDto` for
  `description`/`descriptor`/`notificationUrl`/`extraData` — see `Dto/` above) is non-null.
  `paymentMethod` is omitted entirely when that property is null **or an empty array** (not
  required, and a non-empty-but-still-array PHP value would `json_encode()` to `[]`, not `{}`) —
  the empty-array case is checked explicitly rather than relying on the null check alone, since a
  caller passing `[]` instead of `null` is otherwise indistinguishable from one that means to send
  data. Two unit tests assert on `json_encode()`'s actual output rather than PHP array equality, to
  catch that mismatch. `"redirect"` is added only when `successUrl` **or** `cancelUrl` is non-null,
  and nests only whichever of the two is actually set — a caller providing just one (e.g. only
  `cancelUrl`, if a merchant-level default already covers the success path) still gets a valid
  partial `redirect` object rather than either field silently being sent as `null`.
  `browser` and `customer` are each all-or-nothing per the schema (`browser.ip`/`.referrer`/
  `.userAgent` and `customer.id`/`.email` are required together whenever the parent object is sent
  at all) — `BrowserDto`/`CustomerDto`'s own required constructor parameters (see `Dto/` above) now
  enforce exactly that: a partial object simply can't be constructed, closing what used to be just a
  documented-but-unchecked constraint; a still-malformed body would in any case surface as an
  `ApiException` from the Unified API's own 400 response. `browser` is optional but documented as
  strongly recommended whenever a real end-user request is available: it's what lets the card
  network/issuer attempt a frictionless (challenge-free) 3DS flow instead of always forcing one —
  directly relevant to this ticket's "3DS-pending vs direct success" deliverable, which is precisely
  why omitting it (as the ticket's literal signature would have) was reconsidered.
  `paymentMethod.details`' own sub-fields (`fullName`/`selectedBrand`/`validityDate`) are all
  optional, even though the schema's free-text summary suggested `validityDate` might be required
  when `details` is sent — a real working hosted-fields Postman example omits `validityDate`
  entirely, and that concrete example is trusted over the schema summary on this specific point;
  `HostedFieldDtoValidator` does not enforce anything on `paymentMethod`'s shape for that reason.
  `capture` defaults to `true` (an immediate payment) but can be set to `false` on `CommonFieldsDto`
  for an authorization-only hold — no validation on it either, since both booleans are always valid.
  `accountId` lives on `CommonFieldsDto` (composed within `HostedFieldDto`, see `Dto/` above) rather
  than the service's constructor — unlike `AbstractUnifiedApiService`'s five
  constructor arguments (shared connection configuration: HTTP client, token manager, base URL,
  OAuth2 `clientId`/`clientSecret`), it's data about this specific payment request, not configuration
  shared across every call the service makes, and has no relationship to the OAuth2 `clientId`
  itself. `UnifiedApiHostedPaymentService` therefore has no constructor of its own, inheriting
  `AbstractUnifiedApiService`'s five-argument one directly — matching `UnifiedApiPaymentService`.
  Error handling mirrors `getPayment()` (401-retry, non-2xx throws
  `ApiException`) minus the 404 special case, since there's no resource being looked up by id on a
  create call; `InvalidHostedFieldException` (see `Exceptions/` above) is thrown by the validator
  before any of that — a client-side check, not an API response. The response is parsed only enough
  to distinguish a direct success from a 3DS-pending outcome: a private `extractRedirectUrl()` reads
  `redirect.url` off the JSON body when present (the Unified API's own signal for pending 3DS/SCA,
  though in practice only reachable via `card.threeDSecure.displayMode=raw` on the request — see
  `Output/` above) and returns it as `HostedPaymentOutput::$redirectUrl` (`null` on direct success);
  a sibling private `extractRedirectHtml()` reads `redirect.html` the same way, Base64-decodes it,
  and returns it as `HostedPaymentOutput::$redirectHtml` — the shape actually returned by default
  (no `displayMode` override), confirmed against a real staging 3DS-required response
  (`execCode=0001`) from a consuming plugin's integration test. Malformed JSON, a missing field, a
  non-string value, or (for the html variant) a value that isn't valid Base64 all yield `null`
  rather than throwing, since these methods each extract one derived field, they do not validate
  the full payment representation (same "out of scope" reasoning
  as `getPayment()`). Mapping the eventual outcome to a `PaymentOutcome` constant is explicitly
  **not** this service's job — that's PRE-3588 (parsing the asynchronous webhook/3DS-return
  confirmation), a distinct concern from this synchronous creation call. Matching unit test in
  `tests/Services/` (rewritten to build a `HostedFieldDto` and pass it in, plus a case proving an
  invalid DTO throws before any HTTP call), plus an equivalent `tests/Integration/` test — gated
  behind the same `UPC_IT_*` variables plus `UPC_IT_ACCOUNT_ID` and `UPC_IT_HF_TOKEN`; unlike
  `UPC_IT_PAYMENT_ID`, `UPC_IT_HF_TOKEN` cannot be a static fixture (an hfToken is single-use and
  short-lived), so it must be freshly minted via the hosted-fields JS SDK in a browser immediately
  before each local run.

## Documentation

Every top-level `src/` category (`Auth/`, `Contracts/`, `DataValues/`, `Dto/`, `Exceptions/`,
`Output/`, `Services/`, `Utilities/Helpers/`, `Validators/`) is documented at implementation-detail
depth (real method signatures, validation rules, design rationale) in **this file's Architecture
section above** — one bullet per category — and must be updated in the same task/PR whenever a
category gains, loses, or changes a class, not left for a later cleanup pass. This applies to
whoever is doing the work, human or AI assistant.

`README.md` is deliberately **not** a technical reference as of the `Dto`/`Validators`/
`DataValues`/`Output` split — it's a short, marketing-style overview (what this library is, why a
plugin would use it, a quick-start snippet) that points to the project
**[wiki](https://github.com/payplug/unified-plugin-core/wiki)** for the full API reference and
integration guides. The wiki is maintained separately by the team, not synced automatically by
this file's own rules or by an AI session — don't add per-category `## X` sections back to
`README.md` to "keep it in sync" with `src/`; that's exactly the technical-reference role the wiki
now owns. Docs drifting out of sync between `src/` and *this file* is still a defect; `README.md`
just isn't one of the two sync targets anymore.

## Constraints to preserve

- No PHP syntax newer than 7.1 in `src/` or `tests/`: no typed properties, arrow functions,
  constructor property promotion, `match`, or `enum`. `void` return types,
  `declare(strict_types=1)`, nullable types, and short array syntax are fine (7.0/7.1 features).
  This is enforced at the parser level by the CI `compatibility` job (real PHP 7.1 interpreter),
  not by any single config file.
- `composer.json`'s `require.php` is `>=7.4` **on purpose** — it reflects the build-tooling floor
  of the PrestaShop/WooCommerce plugins that `composer require` this package, not the runtime the
  shipped code executes on. The final plugin is distributed as a marketplace ZIP that bundles this
  library's source directly, without a live `vendor/` install on the merchant's server — so it
  still has to run on PHP 7.1 hosts. Don't conflate the two: the syntax constraint above is about
  what ships in the ZIP; `require.php` is about what can run `composer install` against this
  package during plugin development/CI.
  - This distinction has a sharp edge that PRE-3466 (`PhoneHelper`, the first UPC helper with a
    real runtime dependency) hit directly: `require.php`'s own `>=7.4` value gets baked into
    Composer's generated `vendor/composer/platform_check.php`, which `vendor/autoload.php` runs
    unconditionally — so if `vendor/` is ever bundled as literally installed, that overly
    conservative build-tooling floor would fatal-error on a real PHP 7.1 merchant host, even
    though every actual dependency is genuinely 7.1-compatible. Fixed via
    `"config": {"platform-check": false}` in `composer.json` — deliberate, not a suppressed
    warning: `make verify-71` independently proves the shipped code and its dependencies
    actually run on PHP 7.1 (see Commands above), so disabling the blunt aggregate check trades
    it for a real one.
  - Any new runtime dependency (`require`, not `require-dev`) needs its **actual PHP floor**
    verified against its own upstream `composer.json` — a caret range on a dependency's major
    version is not sufficient proof of compatibility, because a package can raise its own PHP
    floor mid-line without a major bump (this happened to `giggsey/libphonenumber-for-php`
    between `8.13.45` and `8.13.50`). Composer resolves one shared dependency graph across
    `require` and `require-dev` combined (there is only one `vendor/` copy of any package), so a
    transitive dependency shared with dev tooling (e.g. `symfony/polyfill-mbstring`, needed by
    both `giggsey/libphonenumber-for-php` and `friendsofphp/php-cs-fixer`) may need an **exact**
    version pin, not a caret range, chosen where both requirers' constraints and the PHP 7.1 floor
    all overlap. Run `make verify-71` after touching any dependency version — it's the only thing
    that actually proves the floor holds.
- `captainhook/captainhook` must stay in `require-dev` only, never in `require`.
- PSR-4 namespace root is exactly `PayplugUnifiedCore\` (lowercase "plug").

## Tooling config

- `phpstan.neon` — level 8, `phpVersion: 70100` (reasons about the code as PHP 7.1 regardless of
  the PHP 7.4 runtime PHPStan itself executes under — this is what catches accidental use of
  newer syntax semantically).
- `.php-cs-fixer.dist.php` — `@PSR12` + `@PHP71Migration` rule sets, plus `single_quote`, short
  array syntax, `declare_strict_types`, `void_return`, `ordered_imports`, `no_unused_imports`.
- `captainhook.json` — commit messages must match `/^((PRE|SMP)-\d+|PATCH-\d+\.\d+\.\d+(-rc\d+)?): .+/`,
  i.e. either a Jira ticket prefix or a ticket-less `PATCH-X.Y.Z` / `PATCH-X.Y.Z-rcN` prefix for
  fixes that ride along on a patch/release branch with no ticket of their own; branch names must
  match `(feature|fix|hotfix|refactor)/(PRE|SMP)-\d+...` or `(release|patch)/x.y.z` with an
  optional `-rcN` suffix (e.g. `release/0.0.2` or `patch/0.0.2-rc0`); pre-commit also runs
  PHP-CS-Fixer.
- `phpunit.xml.dist` — bootstraps `vendor/autoload.php`, two testsuites: `unit` (`tests/`,
  excluding `tests/Integration/`) and `integration` (`tests/Integration/`, scaffolded empty as of
  PRE-3563 — nothing in the library does genuine I/O yet); `composer.json`'s `test`/`test-coverage`
  scripts target `--testsuite=unit` explicitly (a CLI path argument to `phpunit` would otherwise
  override the testsuite config entirely), and a new `test-integration` script targets
  `--testsuite=integration`;
  `executionOrder="random"` + `resolveDependencies="true"` to surface hidden test-order coupling,
  `failOnWarning`/`failOnRisky`/`beStrictAboutTestsThatDoNotTestAnything`/
  `beStrictAboutOutputDuringTests` all `true` so silent problems (unverified mock expectations,
  empty tests, stray output) become hard failures instead of passing quietly; `<coverage>` scopes
  instrumentation to `src/` (the actual Clover report generation is a `--coverage-clover` CLI flag
  on the `test-coverage` Composer script, not a static `<report>` block, so the output path stays
  visible in `composer.json`/CI config). The suite is unit-only because everything so far
  (`Exceptions/`, `Utilities/Helpers/`) is I/O-free by design — no CMS calls, no network calls;
  `PhoneHelper` has a real Composer dependency (`giggsey/libphonenumber-for-php`) but still no I/O,
  so it stays a unit test. The first class that does real I/O (most likely a Payplug API client,
  given the existing `ApiException`) should trigger splitting this into `unit` + `integration`
  testsuites and adding a matching `tests/Integration/` directory — no E2E tests are planned,
  since this is a frontend-less PHP library.
- `Dockerfile` — the dev image installs PCOV (`pecl install pcov`) as the coverage driver for local
  `make coverage`/`make quality` runs; CI's `coverage` job instead requests
  `coverage: pcov` directly via `shivammathur/setup-php@v2` on the GitHub-hosted runner.

## CI

`.github/workflows/ci.yml` runs on PRs targeting `develop`, `master`, or any `release/**`/
`patch/**` branch — the glob patterns matter because patch branches merge into release branches
(e.g. `patch/0.0.2-rc0` → `release/0.0.2`), not just into `master` directly, and a fixed branch
list missed that hop entirely before this was caught:

- **`compatibility`** — matrix over PHP 7.1/7.4/8.0/8.1/8.2: `php -l` on every file in `src/` and
  `tests/`, directly against the checked-out files (no `composer install` — that would fail on the
  7.1 leg for an unrelated reason, since `require.php` is `>=7.4`; this job is a pure syntax check
  independent of installability). Proves the code parses on every supported runtime.
- **`quality`** — delegates to the reusable workflow
  `payplug/template-ci/.github/workflows/php-quality.yml@main` on PHP 7.4 (static analysis, code
  style, unit tests). This is the authoritative equivalent of local `make quality`.
- **`coverage`** — runs `composer test-coverage` on PHP 7.4 (PCOV via `setup-php`), uploads
  `build/logs/clover.xml` as the `clover-coverage` artifact. Exists as its own job (rather than
  folded into `quality`, which is an external reusable workflow with no coverage support) so
  coverage generation stays this repo's own concern.
- **`sonarcloud`** — `needs: coverage`; delegates to
  `payplug/template-ci/.github/workflows/sonarcloud-coverage.yml@main`, downloads the
  `clover-coverage` artifact and feeds it to SonarCloud as `sonar.php.coverage.reportPaths`, with
  `enforce-quality-gate: true` (a failed SonarCloud Quality Gate fails this job).
  `sonarcloud-coverage.yml` is a new, purely-additive file in `template-ci` (the pre-existing
  `sonarcloud.yml`, used by 4 other Payplug repos with no coverage/unit tests of their own, is
  untouched). The SonarCloud project key is `github-payplug-unified-plugin-core` (also used in the
  README coverage badge) — auto-provisioned successfully on first scan, confirmed via a real CI
  run. The badge's `token=` query parameter is a SonarCloud-issued **badge token**, scoped solely to
  reading that one metric's SVG — not a general API credential — and is meant to be published
  publicly for private projects; this is the intended/documented usage, not a leaked secret.

## Release flow

Branching model: `feature/**` branches PR into `develop`; a `release/X.Y.Z` branch cut from
`develop` becomes a release candidate; once merged into `master`, a manually pushed `X.Y.Z` tag
publishes it. A `patch/*` branch exists to fix a specific version rather than introduce new
scope, and where it's cut from depends on which version it's fixing: `patch/X.Y.Z` (no `-rcN`)
branches from `master` to patch an already-published release; `patch/X.Y.Z-rcN` branches from
the corresponding still-open `release/X.Y.Z` branch to fix that pre-release before it's finalized
(this repo's own `patch/0.0.2-rc0` → `release/0.0.2` is the latter case). Two workflows automate
the tagging/changelog side, both thin wrappers around `payplug/template-ci` reusable workflows
(same pattern as the `quality` CI job):

- **`.github/workflows/release-rc.yml`** — fires on branch creation; if the new branch matches
  `release/*`, calls `auto_tag_rc.yml` (needs the `RELEASE_TOKEN` repo secret — a PAT, since tags
  pushed with the default `GITHUB_TOKEN` don't trigger further workflow runs) to create and push
  a `X.Y.Z-rc0` tag.
- **`.github/workflows/release.yml`** — fires on any tag matching `*.*.*` (this glob catches both
  `X.Y.Z` and `X.Y.Z-rc0`). Routes by whether the tag name contains `-rc`: RC tags get a GitHub
  **pre-release** via `github_release_rc.yml`; plain `X.Y.Z` tags (pushed manually on `master`)
  get a full GitHub **release** via `github_release.yml`. Both auto-generate release notes from
  merged PRs/commits.
