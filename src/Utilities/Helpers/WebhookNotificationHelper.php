<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Utilities\Helpers;

use PayplugUnifiedCore\Exceptions\InvalidNotificationException;

/**
 * Parses and validates an asynchronous "Payment Operation" notification (webhook/3DS
 * confirmation) sent by the Payplug platform, independently of whatever CMS receives the
 * HTTP request.
 *
 * Signature verification: the webhook receiver is configured, in the Payplug back office at
 * webhook-creation time, with a shared secret echoed back in the notification's Authorization
 * header (Basic- or Bearer-style, merchant's choice) — there is no HMAC/signature-over-body
 * scheme. verifySignature() is a constant-time comparison of that header's raw value against
 * the value the CMS expects (sourced from its own configuration storage, e.g.
 * IConfigurationRepository::get()).
 *
 * $headers must be an associative array of header name => raw value, as extracted by the CMS
 * controller from the incoming HTTP request (e.g. a flattened PSR-7 getHeaderLine() map).
 * Header name lookup is case-insensitive, since HTTP header names are.
 */
final class WebhookNotificationHelper
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws InvalidNotificationException if the Authorization header is missing or doesn't match
     */
    public static function verifySignature(array $headers, string $expectedAuthorizationHeader): void
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                if (hash_equals($expectedAuthorizationHeader, $value)) {
                    return;
                }

                throw new InvalidNotificationException('Webhook notification signature does not match.');
            }
        }

        throw new InvalidNotificationException('Webhook notification is missing the Authorization header.');
    }
}
