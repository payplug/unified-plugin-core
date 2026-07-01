# Copilot Instructions

These instructions apply to code reviews on pull requests in this repository.

## Project Context

`payplug/unified-plugin-core` is a PHP library providing core foundations shared across multiple
Payplug e-commerce plugins (e.g. PrestaShop). It is a dependency consumed by those plugins, not a
plugin itself — nothing here should be aware of any specific host platform (no PrestaShop classes,
hooks, or conventions; no controllers, templates, or DB access).

**Stack**: PHP `>=7.1` runtime floor (PrestaShop 1.7.0 host compat) — PHP 7.4 is the actual
dev/CI tooling baseline. Composer library, PSR-4 autoload, PHPUnit 9, PHPStan level 8,
PHP-CS-Fixer, CaptainHook git hooks.

**Current state**: scaffolding only, no business logic yet. `src/` holds four empty top-level
categories waiting for later tickets to add real code.

**Key architectural layout**:
- `src/Contracts/` — interfaces shared across consuming plugins
- `src/Exceptions/` — exception types
- `src/Models/` — plain data/domain models
- `src/Utilities/Helpers/` — stateless helper functions/classes
- `tests/` — PHPUnit tests, PSR-4 root `PayplugUnifiedCore\Tests\`

## Review Scope

- Review only the lines actually added or changed in this PR's diff — not the full contents of
  any file the PR happens to touch.
- Do not flag pre-existing issues in code that wasn't modified, even in a file where other lines
  did change, and even in a file merely linked to or imported by the change.
- Exception: if a change in this PR demonstrably breaks or affects unchanged code (e.g. a changed
  method signature, a renamed contract), it's fine to flag the specific affected call site — that's
  a consequence of this PR, not a pre-existing issue. Don't use this as license for a general audit
  of files the diff references.

## Intentional Patterns — Do Not Flag as Issues

- **PHP 7.1 syntax floor in `src/` and `tests/`** — no typed properties, arrow functions,
  constructor property promotion, `match`, or `enum`, even though the dev tooling itself runs on
  PHP 7.4. This is a retro-compat requirement for PrestaShop 1.7.0 hosts, enforced in CI by a real
  PHP 7.1 interpreter (parser-level, not just a config rule). `void` return types,
  `declare(strict_types=1)`, nullable types, and short array syntax are all fine — they're 7.0/7.1
  features, not violations.
- **`composer.json`'s `require.php` is `>=7.4` on purpose** — that's the build-tooling floor of
  the plugins that `composer require` this package, not the runtime the shipped code executes on
  (see README/CLAUDE.md). Don't suggest lowering it to `>=7.1` to "match" the syntax floor above —
  those two constraints are intentionally independent.
- **`captainhook/captainhook` lives in `require-dev` only** — flag it if a PR moves it to
  `require`, but don't suggest adding other dev tooling there either.
- **`composer.json`'s `post-install-cmd`/`post-update-cmd` guard captainhook with an inline
  `@php -r "if (is_file(...)) { ... }"` check** — intentional, so `composer install --no-dev` (real
  consumers of this library, and any CI job installing runtime-only deps) doesn't crash when the
  dev-only `vendor/bin/captainhook` binary isn't present. Don't suggest simplifying back to a plain
  `vendor/bin/captainhook install --force` string.
- **`Dockerfile` sets a non-root `USER appuser` even though the `Makefile` already runs containers
  with `--user "$(id -u):$(id -g)"`** — not redundant. The `Makefile` override handles the normal
  dev workflow; the image-level `USER` is defense-in-depth for anyone running the image directly
  (`docker run` without going through `make`).
- **`.github/workflows/release-rc.yml` uses a `RELEASE_TOKEN` PAT secret instead of the default
  `GITHUB_TOKEN`** to push the RC tag — required because GitHub deliberately blocks the default
  token's pushes from triggering further workflow runs (anti-loop protection), which would break
  the chain into `release.yml`. Don't suggest switching it to `secrets.GITHUB_TOKEN`.
- **`.github/workflows/release.yml` triggers on a single tag glob `'*.*.*'`** that matches both
  `X.Y.Z` and `X.Y.Z-rc0`, then routes between two jobs via `contains(github.ref_name, '-rc')`.
  This is intentional — GitHub Actions doesn't allow combining `tags` and `tags-ignore` on one
  trigger, so splitting by tag content in job `if:` conditions is the correct approach, not a
  workaround to "fix."

## Code Review Dimensions

### Security
- Secrets or credentials committed in code
- Insecure deserialization, path traversal, SSRF in any new helper/utility
- This library has no HTTP layer, controllers, or database access of its own — don't invent
  web-vulnerability findings (XSS/CSRF/SQLi) unless a PR actually introduces code touching those
  surfaces

### Performance
- Algorithmic complexity in shared helpers — code in `Utilities/Helpers/` runs inside every
  consuming plugin's hot paths
- Unnecessary object allocation in `Models/`
- Resource leaks in anything implementing `__destruct` or handling streams/files

### Correctness
- Edge cases: empty input, null, overflow — especially in `Utilities/Helpers/`, given how broadly
  those get consumed
- `src/Contracts/` interfaces must stay narrow and stable — a breaking signature change here
  breaks every consuming plugin at once
- `src/Exceptions/` types should carry enough context to be actionable for a catching plugin,
  without leaking sensitive data (API keys, card data) into messages
- `declare(strict_types=1)` is required in new files (enforced by `.php-cs-fixer.dist.php`'s
  `declare_strict_types` rule) — flag its absence
- No PHP syntax newer than 7.1 in `src/` or `tests/` — flag any typed property, arrow function,
  constructor promotion, `match`, or `enum`

### Maintainability
- Naming clarity, single responsibility, duplication
- New code must land under one of the four existing `src/` categories (`Contracts/`,
  `Exceptions/`, `Models/`, `Utilities/Helpers/`) rather than introducing new top-level
  directories — flag a new top-level namespace as a design question, not a rubber-stamp
- Test coverage: PHPUnit in `tests/`
- Documentation for non-obvious logic only — do not flag missing comments on self-explanatory code
- Coding standard: PHP-CS-Fixer with `@PSR12` + `@PHP71Migration` (see `.php-cs-fixer.dist.php`)
- PSR-4 namespace root must stay exactly `PayplugUnifiedCore\` (lowercase "plug") — flag any
  deviation

## Output Format

Structure the review comment exactly as follows:

### 1. What's Good

A bullet list of positive observations — things done well, non-obvious correct decisions, solid patterns.

---

### 2. Summary table

A markdown table with two columns: **Dimension** and **Rating**. One row per review dimension. Use emoji inline with the rating text:

| Dimension | Rating |
|---|---|
| Security | ✅ Fine |
| Correctness | ⚠️ Medium (short reason) |
| Performance | ✅ Fine |
| Maintainability | ⚠️ Low (short reason) |

Severity scale:
- ✅ **Fine** — no issues
- ⚠️ **Low / Medium** — should be fixed but not blocking
- ❌ **High / Critical** — must be fixed before merge

---

### 3. Closing one-liner

A single sentence summarising what needs to be addressed before merge (or that the PR is ready if nothing critical).

---

### 4. Individual findings (one section per issue)

Each finding follows this exact structure:

**Heading:** `[Dimension] [emoji] [Severity]` — e.g. `Security ⚠️ Medium`

**Subtitle (bold):** short title followed by the file path and line number as a markdown link — e.g. `**Open redirect in return URL** (StandardPaymentMethod.php:364)`

**Code block:** the relevant snippet from the diff showing the problem.

**Explanation paragraph:** what the risk is and why it matters. Be concrete.

**Fix line:** start with `Fix:` in bold, then a brief description, followed by a code block showing the suggested fix.

Lead with Critical/High findings. Omit the findings section entirely if there are no issues.

## Iterative Reviews

When reviewing a new commit on a PR that already has open review threads:

- **Close/resolve threads yourself** for issues that have been addressed in the new commit — don't
  just note that it's fixed, actually resolve the thread. Do not leave it open or wait for a human
  to close it once the fix is present.
- **Do not re-open or re-comment** on issues that were already resolved in a previous round.
- Only open new threads for issues that are genuinely new or that remain unresolved.
- If a previous finding was partially addressed, update the thread with what still needs attention rather than opening a duplicate.