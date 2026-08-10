# unified-plugin-core

[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=github-payplug-unified-plugin-core&metric=coverage&token=3c0c28c9d7b14862aa675238c8f6d065bd49f363)](https://sonarcloud.io/summary/new_code?id=github-payplug-unified-plugin-core)

**The shared foundation for every Payplug e-commerce plugin** — PrestaShop, WooCommerce, and beyond.

Every Payplug plugin ends up rebuilding the same pieces: OAuth2 authentication, calls to the
Unified API, webhook verification, amount and phone-number formatting quirks. `unified-plugin-core`
builds them once, tests them thoroughly, and lets every plugin share the same dependable core — so
plugin teams can focus on their CMS-specific integration instead of reinventing payment plumbing.

## What's inside

- **Authentication** — OAuth2 + PKCE against Payplug's identity provider, with token caching and
  automatic refresh handled for you.
- **Unified API client** — fetch payments and create hosted-fields payments (including 3DS/SCA
  authentication), through typed, validated objects instead of raw arrays.
- **Webhooks** — signature verification and payload parsing, ready to drop into your CMS's own
  controller and persistence layer.
- **Helpers** — amount rounding, phone number normalization, and PKCE generation: the fiddly
  details every plugin used to reimplement on its own.
- **A focused exception hierarchy** so your plugin can catch exactly the failure it cares about,
  not a generic `\Exception`.
- **PHP 7.1+ compatible** — built and tested on modern PHP, but safe to bundle straight into a
  plugin ZIP running on a legacy merchant server.

## Quick look

```php
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Services\UnifiedApiPaymentService;

$oauth2Client = new OAuth2Client($httpClient, $baseUrl, $redirectUri, $scope, $audience);
$tokenManager = new TokenManager($tokenCache, $oauth2Client);

$paymentService = new UnifiedApiPaymentService($httpClient, $tokenManager, $baseUrl, $clientId, $clientSecret);
$payment = $paymentService->getPayment($paymentId);
```

## Installation

```bash
composer require payplug/unified-plugin-core
```

## Requirements

- PHP 7.1+ at runtime — this library ships bundled directly into a plugin ZIP, not installed via a
  live `vendor/` on the merchant's server, so it has to run on whatever PHP version the merchant is
  actually on.
- Docker for local development — no local PHP/Composer install needed, see **Development** below.

## Documentation

Full architecture, API reference, and integration guides live on the
**[wiki](https://github.com/payplug/unified-plugin-core/wiki)**.

## Development

```bash
make install
```

builds the dev Docker image and installs dependencies. Other useful targets: `make test`,
`make stan`, `make cs-fix`, `make quality`. See `CLAUDE.md` for the full contributor guide.

## License

MIT
