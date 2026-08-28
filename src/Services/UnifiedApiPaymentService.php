<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Services;

use PayplugUnifiedCore\Contracts\PaymentRequestPayload;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Dto\PaymentDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Exceptions\InvalidPaymentException;
use PayplugUnifiedCore\Exceptions\InvalidRefundRequestException;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Exceptions\RefundAmountException;
use PayplugUnifiedCore\Output\PaymentOutput;
use PayplugUnifiedCore\Utilities\Helpers\Assert;
use PayplugUnifiedCore\Validators\HostedFieldDtoValidator;
use PayplugUnifiedCore\Validators\PaymentDtoValidator;

/**
 * Reads payments and payment operations, creates payments, and creates refunds, against the
 * Unified API, authenticated via TokenManager's client-credentials JWT.
 *
 * getPayment() (PRE-3576) fetches a payment and returns the raw HTTP response (status + body),
 * not a parsed payment model — the full payment data model is separate, future scope.
 *
 * getOperation() (PRE-3614) hits the "public" operation endpoint (as opposed to the internal
 * /processing-operations/operations/{id} resource, which a merchant's client credentials cannot
 * reach): its response is a flat, webhook-shaped payload (id/execCode/orderId/amount at the top
 * level) — the same shape WebhookNotificationHelper::parse() already knows how to turn into an
 * OperationData, which is what makes it useful as a polling fallback for a delayed or lost
 * webhook.
 *
 * createPayment() (PRE-3587, moved here from the now-removed UnifiedApiHostedPaymentService at
 * PRE-3590) takes a single PaymentRequestPayload — either a HostedFieldDto (hfToken-driven,
 * optionally also creating an alias) or a PaymentDto (paying with an already-created alias, no
 * card data at all) — validated by the matching validator (HostedFieldDtoValidator or
 * PaymentDtoValidator) before any of its fields are used. Both DTOs hit the same Unified API
 * endpoint with the same request/response shape, so one method covers both without a dedicated
 * method per DTO; the interface is what lets this method accept either without a native PHP union
 * type, which this repo's PHP 7.1 floor doesn't support. It was renamed from createHostedPayment()
 * and moved out of its own dedicated service once it became clear a PaymentDto-based call involves
 * no hosted field at all, making both the old method name and a separate "hosted payment" service
 * misleading for that flow — every payment-creation concern now lives on the one service that also
 * reads payments and operations.
 *
 * accountId — the Unified API processing account the payment is created against — lives on the
 * DTO rather than the service's constructor: unlike clientId/clientSecret/baseUrl, it's data about
 * this specific payment request, not shared connection configuration, and has no relationship to
 * the OAuth2 clientId/clientSecret pair.
 *
 * The request body itself is built entirely by $dto->createPayloadBody() (including capture,
 * which defaults to true on both DTOs but can be set to false for an authorization-only hold) —
 * every field that body needs lives on the DTO, so this service just forwards it to
 * sendPostJson() rather than reconstructing it.
 *
 * createRefund() (PRE-3589) creates a full or partial refund of a payment.
 */
final class UnifiedApiPaymentService extends AbstractUnifiedApiService
{
    // PAYMENT_PATH takes a %s id, for getPayment(); PAYMENTS_PATH is the plain collection endpoint
    // createPayment() POSTs to; REFUND_PATH takes a %s id, for createRefund() — same
    // /api/payment-gateway prefix as the other payment endpoints, confirmed against the real
    // staging API. Similar names, deliberately distinct constants.
    private const PAYMENT_PATH = '/api/payment-gateway/payments/%s';
    private const PAYMENTS_PATH = '/api/payment-gateway/payments';
    private const REFUND_PATH = '/api/payment-gateway/payments/%s/refund';
    // OPERATION_PATH takes a %s id, for getOperation().
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

    /**
     * @throws InvalidHostedFieldException if $dto is a HostedFieldDto that fails validation.
     * @throws InvalidPaymentException if $dto is a PaymentDto that fails validation. Both are
     *                      thrown before any network call.
     * @throws \LogicException if $dto is neither a HostedFieldDto nor a PaymentDto — every
     *                      PaymentRequestPayload implementation must be validated before use, and
     *                      this is a programming error (a new implementation added without wiring
     *                      up its validator here), not a condition a caller can recover from.
     * @throws ApiException if the request fails, returns a non-2xx status, or the response is
     *                      malformed. getCode() carries the HTTP status when one was received,
     *                      and 0 when the client's response shape was unusable.
     */
    public function createPayment(PaymentRequestPayload $dto): PaymentOutput
    {
        if ($dto instanceof HostedFieldDto) {
            HostedFieldDtoValidator::validate($dto);
        } elseif ($dto instanceof PaymentDto) {
            PaymentDtoValidator::validate($dto);
        } else {
            throw new \LogicException(\sprintf('Unsupported PaymentRequestPayload implementation: %s.', \get_class($dto)));
        }

        $url = $this->baseUrl . self::PAYMENTS_PATH;

        $response = $this->sendPostJson($url, $dto->createPayloadBody());

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ApiException(\sprintf('Unified API payment creation request failed with HTTP status %d.', $response['status']), $response['status']);
        }

        $data = json_decode($response['body'], true);

        return new PaymentOutput(
            $response['status'],
            $response['body'],
            $this->extractNestedString($data, 'redirect', 'url'),
            $this->extractRedirectHtml($data),
            $this->extractNestedString($data, 'paymentMethod', 'id')
        );
    }

    /**
     * Reads a two-level-nested string field out of the already-decoded response body — the
     * presence of "redirect.url" in an otherwise-2xx response is the Unified API's own signal that
     * 3DS/SCA authentication is pending; "paymentMethod.id" echoes back an alias just created
     * (hfToken + paymentMethod.saveFutureUsage) or reused (PaymentDto-based payment). $data being
     * anything other than an array (a body that wasn't valid JSON) or missing/non-string at that
     * path yields null rather than an exception: this method only extracts one derived field at a
     * time, it does not validate the full payment representation (out of scope, same as
     * getPayment()).
     *
     * @param mixed $data the json_decode()'d response body
     */
    private function extractNestedString($data, string $outerKey, string $innerKey): ?string
    {
        if (!\is_array($data) || !isset($data[$outerKey][$innerKey]) || !\is_string($data[$outerKey][$innerKey])) {
            return null;
        }

        return $data[$outerKey][$innerKey];
    }

    /**
     * The "recommended for web" 3DS-pending shape, per the same doc as extractNestedString(): a
     * "redirect" object with an "html" field holding a Base64-encoded HTML block (a form that
     * auto-submits the end user to the bank's challenge page) instead of a bare URL — decoded here
     * so the CMS plugin receives the raw HTML ready to inject into its own page, matching the doc's
     * own "decode this string on your server" step. $data being anything other than an array, or
     * missing/non-string/not-valid-Base64 at "redirect.html", all yield null rather than an
     * exception, for the same reason extractNestedString() does: this only extracts one derived
     * field. An empty string is treated the same as absent — base64_decode('') returns '' (not
     * false), so without this check an empty "html" value would come back as "" instead of null.
     *
     * @param mixed $data the json_decode()'d response body
     */
    private function extractRedirectHtml($data): ?string
    {
        if (!\is_array($data) || !isset($data['redirect']['html']) || !\is_string($data['redirect']['html']) || '' === $data['redirect']['html']) {
            return null;
        }

        $decoded = base64_decode($data['redirect']['html'], true);

        return false !== $decoded ? $decoded : null;
    }

    /**
     * Creates a full or partial refund of a payment. Per the Unified API's own createRefund
     * documentation, the refund is keyed by the payment's own id — the same value this library's
     * OperationData/webhook vocabulary already calls "operationId" (see WebhookNotificationHelper),
     * so that's the parameter name used here rather than introducing a second name for the same
     * value. Omitting $amount refunds the payment's full remaining amount; the Unified API itself
     * rejects an amount exceeding what was captured, so that check isn't duplicated here.
     * $orderId, $description, and $submerchantExternalId are all required — confirmed against the
     * real staging API (2026-08-27) by probing each field's absence individually, not merely the
     * GitBook doc: a body carrying only $orderId is rejected with 400
     * ("The parameter \"description\" is missing."), a body carrying only $description is
     * rejected with 400 ("The parameter \"orderId\" is missing."), and a body carrying both but no
     * $submerchantExternalId is rejected with 400 ("The parameter \"subMerchantExternalId\" is
     * missing.") — despite that error text's capitalization, the field the API actually validates
     * on is the same lower-case "submerchantExternalId" key HostedFieldDto::createPayloadBody()
     * already sends at payment-creation time (confirmed empirically: the capitalized key from the
     * error message itself does not satisfy the check, but this lower-case key does), so no new
     * casing convention is introduced. This corrects this method's own prior design-time
     * assumption (recorded here until PRE-3552's real-environment testing on 2026-08-27 corrected
     * it) that $orderId alone satisfied an "at least one of the two" requirement — the true
     * requirement is all three fields together, at least for a submerchant-routed account.
     *
     * @return array{status: int, body: string}
     * @throws InvalidRefundRequestException if $orderId, $description, or $submerchantExternalId
     *                                       is empty — checked locally before any HTTP call, since
     *                                       ApiException carries only the HTTP status, not the
     *                                       API's own response body naming which field was missing.
     * @throws RefundAmountException if $amount is given and is zero or negative
     * @throws PaymentNotFoundException if the Unified API has no payment with that id (HTTP 404).
     *                                  A sibling of ApiException, not a subclass — catching
     *                                  ApiException alone will not catch this.
     * @throws ApiException if the request fails, returns any other non-2xx status, or the response
     *                      is malformed. getCode() carries the HTTP status when one was received,
     *                      and 0 when the client's response shape was unusable.
     */
    public function createRefund(
        string $operationId,
        string $accountId,
        string $orderId,
        string $description,
        string $submerchantExternalId,
        ?int $amount = null
    ): array {
        Assert::notEmpty($orderId, 'orderId', InvalidRefundRequestException::class);
        Assert::notEmpty($description, 'description', InvalidRefundRequestException::class);
        Assert::notEmpty($submerchantExternalId, 'submerchantExternalId', InvalidRefundRequestException::class);

        if ($amount !== null) {
            Assert::positive($amount, 'amount', RefundAmountException::class);
        }

        $url = $this->baseUrl . \sprintf(self::REFUND_PATH, rawurlencode($operationId));

        $body = [
            'account' => ['id' => $accountId],
            'orderId' => $orderId,
            'description' => $description,
            'submerchantExternalId' => $submerchantExternalId,
        ];

        if ($amount !== null) {
            $body['amount'] = $amount;
        }

        $response = $this->sendPostJson($url, $body);

        if ($response['status'] === self::HTTP_NOT_FOUND) {
            throw new PaymentNotFoundException(\sprintf('Unified API has no payment "%s".', $operationId), self::HTTP_NOT_FOUND);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ApiException(\sprintf('Unified API refund request failed with HTTP status %d.', $response['status']), $response['status']);
        }

        return $response;
    }
}
