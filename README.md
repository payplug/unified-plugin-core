# unified-plugin-core

Core foundations shared library for Payplug e-commerce plugins (e.g. PrestaShop).

## Requirements

- Docker (the only local requirement — PHP and Composer run inside a container)
- `composer.json`'s `require.php` is `>=7.4` — this matches the build-tooling floor of the
  PrestaShop/WooCommerce plugins that depend on this package via Composer, not the runtime the
  shipped code executes on.
- Regardless, code under `src/` and `tests/` must not use any PHP syntax newer than 7.1 (no typed
  properties, arrow functions, constructor property promotion, `match`, or `enum`). This library
  ends up bundled directly into a plugin ZIP distributed on marketplaces, without a live `vendor/`
  install on the merchant's server — and that server can be running PrestaShop 1.7.0 on PHP 7.1.

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

PHP 7.1 compatibility is enforced by a dedicated CI job that lints `src/` and `tests/` directly
with `php -l` across PHP 7.1, 7.4, 8.0, 8.1, and 8.2 interpreters — a parser-level check
independent of Composer's own PHP version gate (which would otherwise reject installing this
package on anything below 7.4).

## License

MIT
