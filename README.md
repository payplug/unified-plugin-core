# unified-plugin-core

[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=github-payplug-unified-plugin-core&metric=coverage&token=3c0c28c9d7b14862aa675238c8f6d065bd49f363)](https://sonarcloud.io/summary/new_code?id=github-payplug-unified-plugin-core)

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
- `make coverage` — run the PHPUnit suite with a Clover coverage report at `build/logs/clover.xml`
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

## Exceptions

`PayplugUnifiedCore\Exceptions\PayplugException` is the base type for every exception this
library throws — catch it instead of a generic `\Exception` to handle any error raised by this
package. Five domain-specific subtypes let callers catch more precisely:

- `RefundAmountException`
- `PaymentNotFoundException`
- `InvalidPhoneNumberException`
- `CardOperationException`
- `ApiException`

Each behaves like a standard PHP exception: `new SomeException($message, $code, $previous)`.

## Utilities

`PayplugUnifiedCore\Utilities\Helpers\AmountHelper` converts amounts between a major-unit float
(e.g. a plugin's cart or order total) and the integer number of cents the Payplug API expects:

```php
use PayplugUnifiedCore\Utilities\Helpers\AmountHelper;

AmountHelper::toCents(49.99);   // 4999
AmountHelper::fromCents(4999);  // 49.99
```

`toCents()` corrects the classic floating-point imprecision (`19.99 * 100` evaluates to
`1998.9999999999998` in raw PHP) by rounding before casting to `int`.

## License

MIT
