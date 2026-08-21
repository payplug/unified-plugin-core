<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Services;

use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;

/**
 * Fetches a payment or a payment operation from the Unified API, authenticated via
 * TokenManager's client-credentials JWT. Returns the raw HTTP response (status + body), not a
 * parsed model — the full data model is separate, future scope.
 *
 * getOperation() hits the "public" operation endpoint (as opposed to the internal
 * /processing-operations/operations/{id} resource, which a merchant's client credentials cannot
 * reach): its response is a flat, webhook-shaped payload (id/execCode/orderId/amount at the top
 * level) — the same shape WebhookNotificationHelper::parse() already knows how to turn into an
 * OperationData, which is what makes it useful as a polling fallback for a delayed or lost
 * webhook.
 */
final class UnifiedApiPaymentService extends AbstractUnifiedApiService
{
    private const PAYMENT_PATH = '/payments/%s';
    private const OPERATION_PATH = '/processing-operations/operations/public/%s';
    private const HTTP_NOT_FOUND = 404;

    /**
     * @return array{status: int, body: string}
     * @throws PaymentNotFoundException if the Unified API has no payment with that id (HTTP 404).
     *                                  A sibling of ApiException, not a subclass — catching
     *                                  ApiException alone will not catch this.
     * @throws ApiException if the request fails, returns any other non-2xx status, or the response
     *                      is malformed. getCode() carries the HTTP status when one was received,
     *                      and 0 when the client's response shape was unusable.
     */
    public function getPayment(string $paymentId): array
    {
        $url = $this->baseUrl . \sprintf(self::PAYMENT_PATH, rawurlencode($paymentId));

        $response = $this->sendGet($url);

        // Checked before the generic non-2xx branch: "this payment does not exist" is a distinct,
        // terminal outcome a plugin handles differently from "the API is broken", so it gets the
        // dedicated exception type rather than being flattened into ApiException.
        if ($response['status'] === self::HTTP_NOT_FOUND) {
            throw new PaymentNotFoundException(\sprintf('Unified API has no payment "%s".', $paymentId), self::HTTP_NOT_FOUND);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ApiException(\sprintf('Unified API payment request failed with HTTP status %d.', $response['status']), $response['status']);
        }

        return $response;
    }

    /**
     * @return array{status: int, body: string}
     * @throws ApiException if the request fails or returns a non-2xx status (including 404 — an
     *                      unknown operation id is not distinguished from any other API failure,
     *                      unlike getPayment()'s 404: a caller polling this as a webhook fallback
     *                      treats every failure the same way, so a dedicated exception type would
     *                      add a distinction nothing currently uses).
     */
    public function getOperation(string $operationId): array
    {
        $url = $this->baseUrl . \sprintf(self::OPERATION_PATH, rawurlencode($operationId));

        $response = $this->sendGet($url);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ApiException(\sprintf('Unified API operation request failed with HTTP status %d.', $response['status']), $response['status']);
        }

        return $response;
    }
}
