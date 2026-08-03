<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Utilities\Helpers;

use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PayplugUnifiedCore\Exceptions\InvalidOperationDataException;
use PayplugUnifiedCore\Models\OperationData;

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
 *
 * parse() never itself returns an OperationData with outcome PaymentOutcome::THREE_DS_PENDING:
 * per the platform's own execCode documentation, a fired asynchronous notification always
 * carries a final code by the time it's sent (the transient in-flight codes are never emitted
 * as a webhook). A CMS controller resolves a previously THREE_DS_PENDING OperationData to its
 * final state by calling parse() on the webhook and persisting the OperationData it returns.
 *
 * Example:
 * <code>
 * $expectedHeader = $configurationRepository->get('payplug_webhook_authorization_header');
 * $operationData = WebhookNotificationHelper::parse($headers, $rawBody, $expectedHeader);
 * $paymentRepository->save($operationData);
 * $orderStateMutator->apply($operationData->orderId, $operationData->outcome);
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
     * @throws InvalidNotificationException if no expected header is configured, the Authorization
     *                                       header is missing, or it doesn't match
     */
    public static function verifySignature(array $headers, string $expectedAuthorizationHeader): void
    {
        if ($expectedAuthorizationHeader === '') {
            throw new InvalidNotificationException('No expected Authorization header is configured; cannot verify the notification.');
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
