<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Utilities\Helpers;

use PayplugUnifiedCore\DataValues\OperationData;
use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PayplugUnifiedCore\Exceptions\InvalidOperationDataException;

/**
 * Parses and validates an asynchronous "Payment Operation" notification (webhook/3DS
 * confirmation) sent by the Payplug platform, independently of whatever CMS receives the
 * HTTP request.
 *
 * Signature verification: where a webhook receiver can be configured, in the Payplug back
 * office at webhook-creation time, with a shared secret echoed back in the notification's
 * Authorization header (Basic- or Bearer-style, merchant's choice) — there is no
 * HMAC/signature-over-body scheme. verifySignature() is a constant-time comparison of that
 * header's raw value against the value the CMS expects (sourced from its own configuration
 * storage, e.g. IConfigurationRepository::get()). Not every merchant/account has a way to
 * configure such a secret, though — when the CMS has no expected value to check against,
 * verifySignature() intentionally accepts the notification unverified rather than rejecting
 * every webhook unconditionally.
 *
 * $headers must be an associative array of header name => raw value, as extracted by the CMS
 * controller from the incoming HTTP request (e.g. a flattened PSR-7 getHeaderLine() map).
 * Header name lookup is case-insensitive, since HTTP header names are.
 *
 * parse() CAN return an OperationData with outcome PaymentOutcome::THREE_DS_PENDING: contrary to
 * this class's original assumption that a fired asynchronous notification always carries a final
 * code, PayPlug's notifier has been observed in production (2026-08-21) firing a notification
 * carrying execCode 0001 ("Authentification 3DSecure requise") before the real, final one for the
 * same operation. A caller must treat a THREE_DS_PENDING result as "no new information yet" —
 * leave any stored state as-is — and must not let it consume any idempotency/dedupe tracking
 * meant for the operation's eventual final notification, so the later, final one is still free
 * to apply once it arrives. A CMS controller resolves a previously THREE_DS_PENDING OperationData
 * (whether set by the synchronous creation flow, or by this same situation recurring) to its
 * final state by calling parse() again on a later webhook and persisting the OperationData it
 * returns.
 *
 * Example:
 * <code>
 * $expectedHeader = $configurationRepository->get('payplug_webhook_authorization_header');
 * $operationData = WebhookNotificationHelper::parse($headers, $rawBody, $expectedHeader);
 * if ($operationData->outcome !== PaymentOutcome::THREE_DS_PENDING) {
 *     $paymentRepository->save($operationData);
 *     $orderStateMutator->apply($operationData->orderId, $operationData->outcome);
 * }
 * </code>
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
     * @throws InvalidNotificationException if an expected header is configured and the
     *                                       Authorization header is missing or doesn't match it
     */
    public static function verifySignature(array $headers, string $expectedAuthorizationHeader): void
    {
        // No secret configured means there is nothing to check the notification against. Some
        // merchants have no way to configure a webhook secret at all (no back-office field, no
        // value returned by the platform to configure one with) — failing open here is what lets
        // those merchants receive webhooks at all, rather than rejecting every notification
        // unconditionally. A merchant who does configure a secret is still fully verified below.
        if ($expectedAuthorizationHeader === '') {
            return;
        }

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

    /**
     * @param array<string, string> $headers
     *
     * @throws InvalidNotificationException if the signature doesn't match, the body isn't valid
     *                                       JSON, a required field is missing or has the wrong
     *                                       type, or the resulting OperationData is invalid
     */
    public static function parse(array $headers, string $rawBody, string $expectedAuthorizationHeader): OperationData
    {
        self::verifySignature($headers, $expectedAuthorizationHeader);

        $data = json_decode($rawBody, true);

        if (
            !\is_array($data)
            || !isset($data['id'], $data['execCode'], $data['orderId'], $data['amount'])
            || !\is_string($data['id'])
            || !\is_string($data['execCode'])
            || !\is_string($data['orderId'])
            || !\is_int($data['amount'])
        ) {
            throw new InvalidNotificationException('Webhook notification payload is malformed.');
        }

        $outcome = ExecCodeMapper::toPaymentOutcome((string) $data['execCode']);

        try {
            return new OperationData((string) $data['id'], (string) $data['execCode'], $outcome, (int) $data['amount'], (string) $data['orderId']);
        } catch (InvalidOperationDataException $e) {
            throw new InvalidNotificationException('Webhook notification payload is invalid.', 0, $e);
        }
    }
}
