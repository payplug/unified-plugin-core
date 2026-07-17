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

- `make test` — run the unit PHPUnit suite
- `make test-integration` — run the integration PHPUnit suite (empty as of PRE-3563, scaffolded
  for the first ticket that adds real I/O)
- `make coverage` — run the PHPUnit suite with a Clover coverage report at `build/logs/clover.xml`
- `make stan` — PHPStan level 8 static analysis
- `make cs-lint` — PHP-CS-Fixer dry-run diff
- `make cs-fix` — PHP-CS-Fixer, applies fixes
- `make quality` — `cs-lint` + `stan` + `test` (mirrors the CI `quality` job)
- `make shell` — interactive shell in the dev container, e.g. to run a single test:
  `vendor/bin/phpunit tests/ScaffoldingTest.php`
- `make verify-71` — proves the PHP 7.1 runtime floor actually holds (see Compatibility below)

## Compatibility

PHP 7.1 compatibility is enforced two ways:

- A CI job lints `src/` and `tests/` directly with `php -l` across PHP 7.1, 7.4, 8.0, 8.1, and 8.2
  interpreters — a parser-level check independent of Composer's own PHP version gate (which would
  otherwise reject installing this package on anything below 7.4).
- `make verify-71` goes further: it builds a `--no-dev` vendor tree (what actually ships to
  merchants) and boots it under a real `php:7.1-cli` interpreter, then runs a small smoke script
  exercising `PhoneHelper`/`AmountHelper` end to end. This is what actually caught that a caret
  version range on `giggsey/libphonenumber-for-php` had silently drifted past the PHP 7.1 floor —
  run it after touching any dependency version.

Composer's own `platform-check` (the runtime guard baked into `vendor/composer/platform_check.php`)
is disabled in `composer.json` (`"config": {"platform-check": false}`), since it would otherwise
enforce this repo's own `require.php` (`>=7.4`, a build-tooling floor, not a runtime one) against
the merchant's actual PHP version. `make verify-71` is the real replacement check.

## Contracts

`src/Contracts/` holds the 7 interfaces that define the boundary between this library and each
consuming CMS plugin (first real consumer: UHF/Sylius) — designed around what a CMS needs to
provide, not the not-yet-built Unified API's shape. Each ships with a docblock sketching a Sylius
and a WooCommerce implementation; this library itself contains no concrete implementations.

- `ILogger` — structured logging sink (`debug`/`info`/`error`), decoupled from any CMS's native
  logger.
- `IConfigurationRepository` — OAuth2 client credentials and Hosted Fields public key material,
  sourced from each CMS's own settings storage.
- `IPaymentRepository` — persists `OperationData` and tracks webhook processing state for
  idempotency.
- `IOrderStateMutator` — applies a `PaymentOutcome` to the CMS-native order, identified by order
  ID (not a CMS-native object, since Sylius and WooCommerce orders share no common type).
- `ILock` — per-operation mutex preventing a retried webhook from being processed concurrently
  with itself.
- `ITokenCache` — caches the OAuth2 JWT this library will use against the future Unified API.
- `IOAuthHttpClient` — narrow HTTP contract for OAuth2 token exchange only (not a general-purpose
  Unified API HTTP client, which is separate future scope).

## Exceptions

`PayplugUnifiedCore\Exceptions\PayplugException` is the base type for every exception this
library throws — catch it instead of a generic `\Exception` to handle any error raised by this
package. Seven domain-specific subtypes let callers catch more precisely:

- `RefundAmountException`
- `PaymentNotFoundException`
- `InvalidPhoneNumberException`
- `CardOperationException`
- `ApiException`
- `InvalidOperationDataException`
- `InvalidTokenException`

Each behaves like a standard PHP exception: `new SomeException($message, $code, $previous)`.

## Models

`PayplugUnifiedCore\Models\PaymentOutcome` expresses UPC's payment result intent to the CMS,
decoupled from any CMS's native order-status vocabulary — a set of class constants (a PHP 7.1
stand-in for a PHP 8.1 `enum`):

```php
use PayplugUnifiedCore\Models\PaymentOutcome;

PaymentOutcome::PAID;             // 'paid'
PaymentOutcome::AUTHORIZED;       // 'authorized'
PaymentOutcome::CAPTURE_REQUIRED; // 'capture_required'
PaymentOutcome::THREE_DS_PENDING; // 'three_ds_pending'
PaymentOutcome::REFUNDED;         // 'refunded'
PaymentOutcome::FAILED;           // 'failed'

PaymentOutcome::isValid('paid');  // true
PaymentOutcome::isValid('bogus'); // false
```

`PayplugUnifiedCore\Models\OperationData` is the persistence value object built from a Payplug API
response or webhook payload — its constructor is this library's validation boundary for that data,
throwing `InvalidOperationDataException` on an empty `operationId`/`execCode`/`orderId`, a negative
`amount`, or an `outcome` that isn't a `PaymentOutcome` constant:

```php
use PayplugUnifiedCore\Models\OperationData;
use PayplugUnifiedCore\Models\PaymentOutcome;

$operation = new OperationData('op_123', '4001', PaymentOutcome::PAID, 4999, 'order_456');

$operation->operationId; // 'op_123'
$operation->execCode;    // '4001'
$operation->outcome;     // 'paid'
$operation->amount;      // 4999 (cents)
$operation->orderId;     // 'order_456'
```

`PayplugUnifiedCore\Models\Token` is the validating value object for an OAuth2 token response,
constructed only from data that has already crossed UPC's external boundary (an OAuth2
token-endpoint response) — its constructor throws `InvalidTokenException` on an empty
`accessToken`/`tokenType` or a non-positive `expiresIn`:

```php
use PayplugUnifiedCore\Models\Token;

$token = new Token('jwt-access-token', 3600, 'Bearer');

$token->accessToken; // 'jwt-access-token'
$token->expiresIn;   // 3600
$token->tokenType;   // 'Bearer'
```

`PayplugUnifiedCore\Models\AuthorizationRequest` is the output of
`OAuth2Client::buildAuthorizationUrl()` — the redirect URL plus the `state`/`codeVerifier` the
caller must persist (session) to complete the flow on callback:

```php
use PayplugUnifiedCore\Models\AuthorizationRequest;

$request = new AuthorizationRequest($url, $state, $codeVerifier);

$request->url;          // redirect the merchant's browser here
$request->state;        // persist in session, compare on callback
$request->codeVerifier; // persist in session, needed for the token exchange
```

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

For CMS platforms where the merchant can configure their own rounding algorithm (e.g.
PrestaShop's `PS_ROUND_MODE`), pass the resolved mode explicitly — it only changes the result for
amounts landing exactly on a half-cent boundary:

```php
AmountHelper::toCents(19.995, PHP_ROUND_HALF_EVEN); // 2000
AmountHelper::toCents(19.995, PHP_ROUND_HALF_DOWN); // 1999
```

`PayplugUnifiedCore\Utilities\Helpers\PhoneHelper` normalizes a customer-entered phone number to
E.164 (the format the Payplug API expects) and determines whether it's a mobile line, backed by
`giggsey/libphonenumber-for-php`:

```php
use PayplugUnifiedCore\Utilities\Helpers\PhoneHelper;

PhoneHelper::toE164('06 12 34 56 78', 'FR');  // "+33612345678"
PhoneHelper::isMobile('06 12 34 56 78', 'FR'); // true
```

`$countryCode` is a 2-letter ISO 3166-1 alpha-2 region code (the UK's is `GB`, not `UK`). Invalid
or unparseable input throws `InvalidPhoneNumberException` from both methods.

`PayplugUnifiedCore\Utilities\Helpers\PkceHelper` generates the PKCE material for the
authorization-code flow:

```php
use PayplugUnifiedCore\Utilities\Helpers\PkceHelper;

$codeVerifier = PkceHelper::generateCodeVerifier();
$codeChallenge = PkceHelper::deriveCodeChallenge($codeVerifier); // S256 only
$state = PkceHelper::generateState();
```

## Auth

`PayplugUnifiedCore\Auth\OAuth2Client` implements the OAuth2/PKCE and client-credentials flows
against the identity provider. It has no caching of its own and never calls `header()` — the
caller performs the actual redirect:

```php
use PayplugUnifiedCore\Auth\OAuth2Client;

$client = new OAuth2Client($httpClient, 'https://api.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

// Interactive merchant connection:
$authorizationRequest = $client->buildAuthorizationUrl($clientId);
// redirect to $authorizationRequest->url; persist ->state and ->codeVerifier in session

// On the callback, after checking the returned state matches:
$token = $client->exchangeAuthorizationCode($clientId, $code, $codeVerifier);

// Background API calls:
$token = $client->getClientCredentialsToken($clientId, $clientSecret);
```

`PayplugUnifiedCore\Auth\TokenManager` wraps the client-credentials flow with caching, for
background API calls that shouldn't hit the identity provider on every request:

```php
use PayplugUnifiedCore\Auth\TokenManager;

$tokenManager = new TokenManager($tokenCache, $client);

$accessToken = $tokenManager->getValidToken($clientId, $clientSecret); // string JWT, ready for an Authorization header
```

## License

MIT
