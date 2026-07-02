# unified-plugin-core

Core foundations shared library for Payplug e-commerce plugins (e.g. PrestaShop).

## Requirements

- Docker (the only local requirement — PHP and Composer run inside a container)
- PHP `>=7.1` is the runtime floor this library targets, chosen for compatibility with
  PrestaShop 1.7.0 hosts. Code under `src/` and `tests/` must not use any PHP syntax newer than
  7.1 (no typed properties, arrow functions, constructor property promotion, `match`, or `enum`).

## Getting started

```
make install
```

This builds the dev Docker image (PHP 7.4-cli + Composer — the actual dev/CI tooling baseline)
and runs `composer install` inside it, including the CaptainHook git hooks setup.

Other targets:

- `make test` — run the PHPUnit suite
- `make stan` — PHPStan level 8 static analysis
- `make cs-lint` — PHP-CS-Fixer dry-run diff
- `make cs-fix` — PHP-CS-Fixer, applies fixes
- `make quality` — `cs-lint` + `stan` + `test` (mirrors the CI `quality` job)
- `make shell` — interactive shell in the dev container, e.g. to run a single test:
  `vendor/bin/phpunit tests/ScaffoldingTest.php`

## Compatibility

PHP 7.1 compatibility is enforced by a dedicated CI job that lints `src/` with a real PHP 7.1
interpreter (a parser-level check — PHPStan and PHP-CS-Fixer alone can't catch every case, since
they run under PHP 7.4). The CI matrix additionally verifies `src/` on PHP 7.4, 8.0, 8.1, and 8.2.

## License

MIT
