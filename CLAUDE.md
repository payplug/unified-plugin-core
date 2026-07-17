# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

`payplug/unified-plugin-core` is a PHP library providing core foundations shared across Payplug
e-commerce plugins (e.g. PrestaShop). Beyond the scaffolding — composer manifest, PSR-4 directory
skeleton, static analysis, code style, git hooks, test harness, CI, and a Dockerized dev
environment — the library now provides a domain exception hierarchy under `src/Exceptions/`,
three utility classes under `src/Utilities/Helpers/` (`AmountHelper`, dependency-free; `PhoneHelper`,
backed by `giggsey/libphonenumber-for-php`, the library's first real runtime dependency — see
"Constraints to preserve" for what that changed; and `PkceHelper`, dependency-free), four value
objects under `src/Models/`: `PaymentOutcome` (payment-result constants), `OperationData`
(validating persistence value object), `Token` (validating OAuth2 token value object), and
`AuthorizationRequest` (unvalidated PKCE redirect output), the 7 core interfaces under
`src/Contracts/` (`ILogger`, `IConfigurationRepository`, `IPaymentRepository`,
`IOrderStateMutator`, `ILock`, `ITokenCache`, `IOAuthHttpClient`) that define the boundary between
UPC and each consuming CMS plugin, and `src/Auth/` (`OAuth2Client`, `TokenManager`) implementing
the OAuth2/PKCE and client-credentials flows against the identity provider.

## Commands

All commands run inside a `unified-plugin-core-dev` Docker image (PHP 7.4-cli + Composer, matching
the CI quality baseline) via a bind mount, so no local PHP/Composer install is required — just a
running Docker daemon. The image builds automatically the first time any target runs.

- `make install` — `composer install` inside the container (also runs
  `vendor/bin/captainhook install --force` via Composer's `post-install-cmd`)
- `make test` — run the unit PHPUnit suite (`vendor/bin/phpunit --testsuite=unit`)
- `make test-integration` — run the integration PHPUnit suite (`vendor/bin/phpunit
  --testsuite=integration`); empty as of PRE-3563, scaffolded for the first ticket that adds real
  I/O
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
- `src/` is organized into five top-level categories: `Auth/`, `Contracts/`, `Exceptions/`,
  `Models/`, `Utilities/Helpers/`. New code should land under the matching category rather than
  introducing new top-level directories.
- `Exceptions/` holds the domain exception hierarchy: `PayplugException` (base, extends
  `\Exception` directly) and seven subtypes — `RefundAmountException`, `PaymentNotFoundException`,
  `InvalidPhoneNumberException`, `CardOperationException`, `ApiException`,
  `InvalidOperationDataException`, `InvalidTokenException` — each a plain marker class extending
  `PayplugException` directly, with no custom constructor or properties, so CMS plugins can catch
  specific error types instead of a generic exception. Any future addition to this hierarchy should
  follow the same pattern: one class per file, no PHP 7.1-incompatible syntax, and a matching test
  in `tests/Exceptions/` verifying the `instanceof` chain and the inherited message/code/previous
  constructor contract. Because PHPStan level 8 includes the `phpstan-phpunit` extension, an
  `assertInstanceOf()` check against a statically-provable `extends` relationship needs an inline
  `// @phpstan-ignore-next-line staticMethod.alreadyNarrowedType` comment directly above it (see
  any file in `tests/Exceptions/` for the exact pattern) — the assertion is kept as a regression
  guard, not removed.
- `Models/` holds value objects with no CMS/network I/O of their own. `PaymentOutcome` is a
  non-instantiable constants container (`final class` + private `@codeCoverageIgnore`d
  constructor, same pattern as the `Utilities/Helpers/` classes below) holding 6 string constants
  (`PAID`, `AUTHORIZED`, `CAPTURE_REQUIRED`, `THREE_DS_PENDING`, `REFUNDED`, `FAILED`) — a PHP 7.1
  stand-in for a PHP 8.1 `enum` — plus `isValid(string $value): bool`. `OperationData` is the
  persistence value object `IPaymentRepository` (PRE-3467, not yet implemented) will work with:
  public properties (`operationId`, `execCode`, `outcome`, `amount`, `orderId`, each with a
  `/** @var */` docblock — PHP 7.1 predates typed properties) set through a validating
  constructor. Per this library's "never trust external I/O" rule, `OperationData`'s constructor
  is the validation boundary — it rejects an empty `operationId`/`execCode`/`orderId`, a negative
  `amount`, or an `outcome` that isn't a `PaymentOutcome` constant, throwing the new
  `InvalidOperationDataException` (6th subtype in the `Exceptions/` hierarchy above). `execCode`
  is typed `string`, not `int`: Payplug's execution-codes documentation describes it as a numeric
  string (e.g. `"4001"`, `"6003"`) from an open-ended, growing catalog, so only non-emptiness is
  validated, not a specific digit pattern. `amount` is `int` centimes, matching
  `AmountHelper::toCents()`'s output convention. Matching tests in `tests/Models/`.
  `Token` (PRE-3563) is the validating value object for a freshly-minted OAuth2 token response
  (`accessToken`, `expiresIn`, `tokenType`, each with a `/** @var */` docblock), constructed only
  from data that has already crossed UPC's external boundary (an OAuth2 token-endpoint
  response) — its constructor rejects an empty `accessToken`/`tokenType` or a non-positive
  `expiresIn`, throwing the new `InvalidTokenException` (7th subtype in the `Exceptions/`
  hierarchy). `AuthorizationRequest` (PRE-3563) is the output of
  `OAuth2Client::buildAuthorizationUrl()` (`url`, `state`, `codeVerifier`) — unlike every other
  `Models/` value object, its constructor holds no validation, since it's produced entirely
  internally by `OAuth2Client` and never crosses an external boundary itself.
- `Contracts/` holds the 7 interfaces that define the boundary between UPC and each consuming CMS
  plugin (first real consumer: UHF/Sylius) — designed around what a CMS needs to provide, not
  around the not-yet-built Unified API's shape, so they survive that later transition intact. All
  7 are pure interfaces (no logic, nothing for PHPUnit to exercise — PHPStan level 8 verifies
  signatures statically instead), PHP 7.1-compatible, each with a class-level docblock sketching
  one Sylius and one WooCommerce implementation (illustrative only, not shipped code) instead of
  the single-call-site `<code>` example used by `Utilities/Helpers/`. `ILogger` (`debug`/`info`/
  `error`, each `(string $message, array $context = []): void`) is a structured logging sink
  decoupled from any CMS's native logger. `IConfigurationRepository` (`get(string $key): ?string`,
  `set(string $key, string $value): void`, `getClientId()`, `getClientSecret()`,
  `getPublicKeyId()`, `getPublicKeyValue()`, all `: string`) sources OAuth2 credentials and Hosted
  Fields public key material from each CMS's own settings storage. `IPaymentRepository`
  (`getByOrderId`/`getByOperationId(string): OperationData`, both `@throws
  PaymentNotFoundException` — the first user of that previously-unused exception subtype — plus
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
  exchange only (PRE-3563) — not a general-purpose Unified API HTTP client, which stays a separate,
  future ticket so this contract doesn't prematurely guess that shape.
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
- `src/Auth/` (PRE-3563) holds the two classes with real OAuth2 logic — everything else this
  ticket adds (`IOAuthHttpClient`, `PkceHelper`, `Token`, `AuthorizationRequest`) is a contract,
  helper, or value object slotting into an existing category. `OAuth2Client` (`final class`) is
  pure token mechanics against the identity provider, with no caching of its own:
  `buildAuthorizationUrl(string $clientId): AuthorizationRequest` generates the PKCE
  verifier/challenge/state via `PkceHelper`
  and returns the redirect URL without calling `header()` itself (the caller performs the actual
  redirect); `exchangeAuthorizationCode(string $clientId, string $code, string $codeVerifier):
  Token` and `getClientCredentialsToken(string $clientId, string $clientSecret): Token` both POST
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
  access-token `string`, not the full `Token` object: `ITokenCache` only stores a single string
  value, so round-tripping `Token`'s other fields through the cache would mean either serializing
  them (leaving a misleading `expiresIn` that reflects the original grant, not remaining time — the
  cache's own shortened TTL is what actually enforces freshness) or not bothering, since
  `tokenType` is always `"Bearer"` for this flow anyway.

## Documentation

Every top-level `src/` category (`Auth/`, `Contracts/`, `Exceptions/`, `Models/`,
`Utilities/Helpers/`) is documented in two places, and both must be updated in the same task/PR
whenever a category gains, loses, or changes a class — not left for a later cleanup pass:

- **This file's Architecture section above** — one bullet per category, at implementation-detail
  depth (real method signatures, validation rules, design rationale).
- **`README.md`** — one section per category (`## Auth`, `## Contracts`, `## Exceptions`,
  `## Models`, `## Utilities`), at usage depth (what a consumer calls, or — for `Contracts/`,
  which has no concrete implementations in this library — a one-line purpose per interface).

This applies to whoever is doing the work, human or AI assistant: when a task adds a class to an
existing category or introduces a new one, its own checklist includes updating both files, the
way Task 7 of the PRE-3467 plan did for `CLAUDE.md` and a same-day follow-up did for `README.md`.
Docs drifting out of sync with `src/` is a defect, not a nice-to-have.

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
