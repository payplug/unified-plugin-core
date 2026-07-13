# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

`payplug/unified-plugin-core` is a PHP library providing core foundations shared across Payplug
e-commerce plugins (e.g. PrestaShop). Beyond the scaffolding — composer manifest, PSR-4 directory
skeleton, static analysis, code style, git hooks, test harness, CI, and a Dockerized dev
environment — the library now provides a domain exception hierarchy under `src/Exceptions/`, two
utility classes under `src/Utilities/Helpers/` (`AmountHelper`, dependency-free, and `PhoneHelper`,
backed by `giggsey/libphonenumber-for-php`, the library's first real runtime dependency — see
"Constraints to preserve" for what that changed), and two value objects under `src/Models/`:
`PaymentOutcome` (payment-result constants) and `OperationData` (validating persistence value
object). `Contracts/` is still empty, held open by `.gitkeep`, for later tickets.

## Commands

All commands run inside a `unified-plugin-core-dev` Docker image (PHP 7.4-cli + Composer, matching
the CI quality baseline) via a bind mount, so no local PHP/Composer install is required — just a
running Docker daemon. The image builds automatically the first time any target runs.

- `make install` — `composer install` inside the container (also runs
  `vendor/bin/captainhook install --force` via Composer's `post-install-cmd`)
- `make test` — run the PHPUnit suite (`vendor/bin/phpunit tests`)
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
- `src/` is organized into four top-level categories: `Contracts/`, `Exceptions/`, `Models/`,
  `Utilities/Helpers/`. `Contracts/` is still empty (held open with `.gitkeep`); new code should
  land under the matching category rather than introducing new top-level directories.
- `Exceptions/` holds the domain exception hierarchy: `PayplugException` (base, extends
  `\Exception` directly) and six subtypes — `RefundAmountException`, `PaymentNotFoundException`,
  `InvalidPhoneNumberException`, `CardOperationException`, `ApiException`,
  `InvalidOperationDataException` — each a plain marker class extending `PayplugException`
  directly, with no custom constructor or properties, so CMS
  plugins can catch specific error types instead of a generic exception. Any future addition to
  this hierarchy should follow the same pattern: one class per file, no PHP 7.1-incompatible
  syntax, and a matching test in `tests/Exceptions/` verifying the `instanceof` chain and the
  inherited message/code/previous constructor contract. Because PHPStan level 8 includes the
  `phpstan-phpunit` extension, an `assertInstanceOf()` check against a statically-provable
  `extends` relationship needs an inline
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
- `phpunit.xml.dist` — bootstraps `vendor/autoload.php`, single `unit` testsuite over `tests/`;
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
