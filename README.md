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

`src/Contracts/` holds the 8 interfaces that define the boundary between this library and each
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
- `IUnifiedApiHttpClient` — narrow HTTP contract for calling the Unified API: `get()` for reading
  resources (payment retrieval) and `postJson()` for creating them (hosted-fields payment
  creation).

## Exceptions

`PayplugUnifiedCore\Exceptions\PayplugException` is the base type for every exception this
library throws — catch it instead of a generic `\Exception` to handle any error raised by this
package. Eight domain-specific subtypes let callers catch more precisely:

- `RefundAmountException`
- `PaymentNotFoundException`
- `InvalidPhoneNumberException`
- `CardOperationException`
- `ApiException`
- `InvalidOperationDataException`
- `InvalidTokenException`
- `InvalidNotificationException`

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

`PayplugUnifiedCore\Models\HostedPaymentResult` is the output of
`UnifiedApiHostedPaymentService::createHostedPayment()` — like `AuthorizationRequest`, its
constructor holds no validation, since it's produced entirely internally from an already-checked
Unified API response. `redirectUrl` is `null` on a direct success, or the URL to redirect the
end-user to for 3DS/SCA authentication otherwise:

```php
use PayplugUnifiedCore\Models\HostedPaymentResult;

$result = new HostedPaymentResult(200, $rawJsonBody, null);

$result->status;      // HTTP status
$result->body;        // raw JSON string from the Unified API
$result->redirectUrl; // null (direct success) or a 3DS redirect URL
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

`PayplugUnifiedCore\Utilities\Helpers\ExecCodeMapper` maps a Payplug `execCode` to a
`PaymentOutcome`:

```php
use PayplugUnifiedCore\Utilities\Helpers\ExecCodeMapper;

ExecCodeMapper::toPaymentOutcome('0000'); // PaymentOutcome::PAID
ExecCodeMapper::toPaymentOutcome('4008'); // PaymentOutcome::FAILED
```

`PayplugUnifiedCore\Utilities\Helpers\WebhookNotificationHelper` verifies and parses an
asynchronous payment notification (webhook/3DS confirmation), independently of any CMS:

```php
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PayplugUnifiedCore\Utilities\Helpers\WebhookNotificationHelper;

$expectedHeader = $configurationRepository->get('payplug_webhook_authorization_header');
if ($expectedHeader === null) {
    throw new InvalidNotificationException('Webhook authorization header is not configured.');
}

$operationData = WebhookNotificationHelper::parse($headers, $rawBody, $expectedHeader);

$paymentRepository->save($operationData);
$orderStateMutator->apply($operationData->orderId, $operationData->outcome);
```

Both `verifySignature()` and `parse()` throw `InvalidNotificationException` — on a missing or
non-matching `Authorization` header, a malformed body, a missing required field, or an invalid
resulting `OperationData`.

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

`refreshToken()` is the escape hatch for a caller holding a token the API just rejected — it drops
the cached entry and mints a replacement, so a token invalidated before its cache TTL expires
(rotated secret, revoked grant, clock skew) doesn't keep failing every call:

```php
$accessToken = $tokenManager->refreshToken($clientId, $clientSecret); // bypasses the cache
```

## Services

`PayplugUnifiedCore\Services\UnifiedApiPaymentService` fetches a payment from the Unified API,
authenticated via `TokenManager`'s client-credentials JWT. It returns the raw HTTP response — a
parsed payment data model is separate, future scope:

```php
use PayplugUnifiedCore\Services\UnifiedApiPaymentService;

$service = new UnifiedApiPaymentService($httpClient, $tokenManager, 'https://api.payplug.com', $clientId, $clientSecret);

$response = $service->getPayment('5298ff38-883a-465f-b759-aec78cee203e');

$response['status']; // 200
$response['body'];   // raw JSON string from the Unified API
```

Error handling:

- **404** throws `PaymentNotFoundException` — a *sibling* of `ApiException`, not a subclass, so
  catching `ApiException` alone will not catch a missing payment.
- **401** is retried once with a freshly minted JWT (the cached one is discarded first); only a
  second 401 throws.
- Any other non-2xx status, or a malformed `IUnifiedApiHttpClient` response, throws `ApiException`.

Both exception types carry the HTTP status as their exception code, so you can branch without
parsing the message. The code is `0` only when the response shape was unusable and no status was
received:

```php
try {
    $response = $service->getPayment($paymentId);
} catch (PaymentNotFoundException $e) {
    // $e->getCode() === 404
} catch (ApiException $e) {
    // $e->getCode() === 503, 500, … or 0 if the HTTP client returned an unusable shape
}
```

`PayplugUnifiedCore\Services\UnifiedApiHostedPaymentService` creates/confirms a payment from a
hosted-fields token (`hfToken`), the create-side sibling of `UnifiedApiPaymentService`. `$accountId`
identifies the Unified API processing account and is unrelated to the OAuth2 `$clientId`:

```php
use PayplugUnifiedCore\Services\UnifiedApiHostedPaymentService;

$service = new UnifiedApiHostedPaymentService($httpClient, $tokenManager, 'https://api.payplug.com', $clientId, $clientSecret, $accountId);

$result = $service->createHostedPayment(
    $hfToken,
    1000,
    'EUR',
    'order_456',
    ['ip' => $request->getClientIp(), 'referrer' => $request->headers->get('referer'), 'userAgent' => $request->headers->get('user-agent')], // browser, optional but strongly recommended
    ['id' => $customerId, 'email' => $customerEmail], // customer, optional
    'Order #456',                                     // description, optional
    ['details' => ['fullName' => 'John Snow', 'selectedBrand' => 'visa']], // paymentMethod, optional
    'MY SHOP Order #456',                                     // descriptor, optional — bank statement label
    'https://shop.example.com/payplug/notification',         // notificationUrl, optional — webhook URL
    'internal_ref_789'                                        // extraData, optional — echoed back in the notification
);

$result->status;      // HTTP status
$result->body;         // raw JSON string from the Unified API
$result->redirectUrl;  // null on direct success; a 3DS/SCA redirect URL when authentication is pending
```

Only `hfToken`/`amount`/`currency`/`orderId` are required; every other parameter is optional (`null`
by default) — matching the Unified API's own doc, where only `account` and `amount` are required at
the request's top level; the `paymentMethod` key itself is omitted from the request entirely when
the `$paymentMethod` argument is `null`, rather than sent as an empty object. `browser` and
`customer`, when passed, each require *all* their sub-fields together (the schema has no partial
form for either) — but passing `browser` whenever a real end-user request is available is strongly
recommended: card networks use it to decide whether a 3DS challenge can be skipped (frictionless)
instead of always being forced, which is directly relevant to this service's own
3DS-pending-vs-direct-success behavior. `descriptor`/`notificationUrl`/`extraData` are plain
pass-throughs (bank statement label, webhook URL, and free-form text echoed back in that webhook) —
deliberately not extended further to cover recurring/subscription or marketplace-specific fields
the Unified API also exposes, since those are outside this service's scope (hosted-fields card
payment + 3DS).

Payments are always created with immediate capture (`capture: true` — there is no capture parameter
on `createHostedPayment()`, so an authorization-only hold isn't supported). Error handling mirrors
`getPayment()`'s 401-retry and non-2xx-throws-`ApiException` behavior, minus the 404 special case
(there's no resource being looked up by id here). Mapping the eventual payment outcome to a
`PaymentOutcome` constant — once the asynchronous webhook/3DS-return confirmation comes back — is a
separate concern (see PRE-3588); this service only reports what's known synchronously, at creation
time.

## License

MIT
