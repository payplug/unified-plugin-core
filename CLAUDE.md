# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

`payplug/unified-plugin-core` is a PHP library providing core foundations shared across Payplug
e-commerce plugins (e.g. PrestaShop). The repository currently contains only scaffolding —
composer manifest, PSR-4 directory skeleton, static analysis, code style, git hooks, test harness,
CI, and a Dockerized dev environment — no business logic yet. Later tickets build real code on top
of this baseline.

## Commands

All commands run inside a `unified-plugin-core-dev` Docker image (PHP 7.4-cli + Composer, matching
the CI quality baseline) via a bind mount, so no local PHP/Composer install is required — just a
running Docker daemon. The image builds automatically the first time any target runs.

- `make install` — `composer install` inside the container (also runs
  `vendor/bin/captainhook install --force` via Composer's `post-install-cmd`)
- `make test` — run the PHPUnit suite (`vendor/bin/phpunit tests`)
- `make stan` — PHPStan level 8 analysis (`phpstan.neon`)
- `make cs-lint` — PHP-CS-Fixer dry-run diff, no changes written
- `make cs-fix` — PHP-CS-Fixer, applies fixes
- `make quality` — `cs-lint` + `stan` + `test` in sequence (mirrors the CI `quality` job)
- `make shell` — interactive `bash` in the dev container; use this to run a single test or any
  other one-off command, e.g. `vendor/bin/phpunit tests/ScaffoldingTest.php` or
  `vendor/bin/phpunit --filter testMethodName`
- `make build` — rebuild the Docker image explicitly (rarely needed; other targets depend on it)

## Architecture

- PSR-4 autoload root: `PayplugUnifiedCore\` → `src/`; dev-only autoload root:
  `PayplugUnifiedCore\Tests\` → `tests/`.
- `src/` is organized into four top-level categories (currently empty, held open with `.gitkeep`):
  `Contracts/`, `Exceptions/`, `Models/`, `Utilities/Helpers/`. New code should land under the
  matching category rather than introducing new top-level directories.

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
- `captainhook/captainhook` must stay in `require-dev` only, never in `require`.
- PSR-4 namespace root is exactly `PayplugUnifiedCore\` (lowercase "plug").

## Tooling config

- `phpstan.neon` — level 8, `phpVersion: 70100` (reasons about the code as PHP 7.1 regardless of
  the PHP 7.4 runtime PHPStan itself executes under — this is what catches accidental use of
  newer syntax semantically).
- `.php-cs-fixer.dist.php` — `@PSR12` + `@PHP71Migration` rule sets, plus `single_quote`, short
  array syntax, `declare_strict_types`, `void_return`, `ordered_imports`, `no_unused_imports`.
- `captainhook.json` — commit messages must match `/^(PRE|SMP)-\d+: .+/`; branch names must match
  `(feature|fix|hotfix|refactor)/(PRE|SMP)-\d+...` or `(release|patch)/x.y.z`; pre-commit also runs
  PHP-CS-Fixer.
- `phpunit.xml.dist` — bootstraps `vendor/autoload.php`, single `unit` testsuite over `tests/`.

## CI

`.github/workflows/ci.yml` runs on PRs targeting `develop` (feature branches) or `master` (release
branches merging to production — the same gate applies to both merge paths):

- **`compatibility`** — matrix over PHP 7.1/7.4/8.0/8.1/8.2: `php -l` on every file in `src/` and
  `tests/`, directly against the checked-out files (no `composer install` — that would fail on the
  7.1 leg for an unrelated reason, since `require.php` is `>=7.4`; this job is a pure syntax check
  independent of installability). Proves the code parses on every supported runtime.
- **`quality`** — delegates to the reusable workflow
  `payplug/template-ci/.github/workflows/php-quality.yml@main` on PHP 7.4 (static analysis, code
  style, unit tests). This is the authoritative equivalent of local `make quality`.

## Release flow

Branching model: `feature/**` branches PR into `develop`; a `release/X.Y.Z` branch cut from
`develop` becomes a release candidate; once merged into `master`, a manually pushed `X.Y.Z` tag
publishes it. Two workflows automate the tagging/changelog side, both thin wrappers around
`payplug/template-ci` reusable workflows (same pattern as the `quality` CI job):

- **`.github/workflows/release-rc.yml`** — fires on branch creation; if the new branch matches
  `release/*`, calls `auto_tag_rc.yml` (needs the `RELEASE_TOKEN` repo secret — a PAT, since tags
  pushed with the default `GITHUB_TOKEN` don't trigger further workflow runs) to create and push
  a `X.Y.Z-rc0` tag.
- **`.github/workflows/release.yml`** — fires on any tag matching `*.*.*` (this glob catches both
  `X.Y.Z` and `X.Y.Z-rc0`). Routes by whether the tag name contains `-rc`: RC tags get a GitHub
  **pre-release** via `github_release_rc.yml`; plain `X.Y.Z` tags (pushed manually on `master`)
  get a full GitHub **release** via `github_release.yml`. Both auto-generate release notes from
  merged PRs/commits.
