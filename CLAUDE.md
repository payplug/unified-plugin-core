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

Known issue: `make stan` currently fails on `tests/ScaffoldingTest.php` — PHPStan 2.x flags
`assertTrue(version_compare(...))` as tautological (`treatPhpDocTypesAsCertain`). Pre-existing, not
yet fixed.

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
- `composer.json`'s `require.php` floor must stay `>=7.1` — retro-compatibility requirement for
  PrestaShop 1.7.0 hosts. Don't bump it to chase a newer language feature.
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

`.github/workflows/ci.yml` runs on PRs targeting `main`:

- **`compatibility`** — matrix over PHP 7.1/7.4/8.0/8.1/8.2: `composer install --no-dev`, then
  `php -l` on every file in `src/`. Proves `src/` parses on every supported runtime.
- **`quality`** — delegates to the reusable workflow
  `payplug/template-ci/.github/workflows/php-quality.yml@main` on PHP 7.4 (static analysis, code
  style, unit tests). This is the authoritative equivalent of local `make quality`.
