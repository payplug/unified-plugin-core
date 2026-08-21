<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Services;

use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\OperationNotFoundException;

/**
 * Fetches a single payment operation from the Unified API, authenticated via TokenManager's
 * client-credentials JWT. Returns the raw HTTP response (status + body), not a parsed model —
 * same reasoning as UnifiedApiPaymentService: the full operation representation is far richer
 * than any single caller needs, so parsing it out is left to the CMS plugin.
 *
 * A sibling of UnifiedApiPaymentService, not a method on it: an "operation" (one processing
 * event — a payment, a capture, a refund — identified by an id from a payment's own
 * operationIds array) is a distinct resource from the "payment" it belongs to, with its own
 * endpoint and its own not-found case.
 *
 * Notably, an operation's representation carries transaction.status.execCode — the same
 * execCode vocabulary ExecCodeMapper already maps from the webhook and payment-creation flows —
 * unlike the payment representation itself, which does not surface an execCode at all. A caller
 * polling for a payment's outcome (e.g. as a fallback for a delayed webhook) should fetch the
 * operation, not the payment.
 */
final class UnifiedApiOperationService extends AbstractUnifiedApiService
{
    private const OPERATION_PATH = '/processing-operations/operations/%s';
    private const HTTP_NOT_FOUND = 404;

    /**
     * @return array{status: int, body: string}
     * @throws OperationNotFoundException if the Unified API has no operation with that id (HTTP
     *                                     404). A sibling of ApiException, not a subclass —
     *                                     catching ApiException alone will not catch this.
     * @throws ApiException if the request fails, returns any other non-2xx status, or the response
     *                      is malformed. getCode() carries the HTTP status when one was received,
     *                      and 0 when the client's response shape was unusable.
     */
    public function getOperation(string $operationId): array
    {
        $url = $this->baseUrl . \sprintf(self::OPERATION_PATH, rawurlencode($operationId));

        $response = $this->sendGet($url);

        if ($response['status'] === self::HTTP_NOT_FOUND) {
            throw new OperationNotFoundException(\sprintf('Unified API has no operation "%s".', $operationId), self::HTTP_NOT_FOUND);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ApiException(\sprintf('Unified API operation request failed with HTTP status %d.', $response['status']), $response['status']);
        }

        return $response;
    }
}
