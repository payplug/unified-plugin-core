<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Services;

use PayplugUnifiedCore\Contracts\PaymentRequestPayload;
use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Dto\PaymentDto;
use PayplugUnifiedCore\Exceptions\AmountExceedsAvailableException;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\AuthorizationExpiredException;
use PayplugUnifiedCore\Exceptions\CancellationAmountException;
use PayplugUnifiedCore\Exceptions\CaptureAmountException;
use PayplugUnifiedCore\Exceptions\CardOperationException;
use PayplugUnifiedCore\Exceptions\InvalidCancellationRequestException;
use PayplugUnifiedCore\Exceptions\InvalidCaptureRequestException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Exceptions\InvalidPaymentException;
use PayplugUnifiedCore\Exceptions\InvalidRefundRequestException;
use PayplugUnifiedCore\Exceptions\MultipleCaptureNotAllowedException;
use PayplugUnifiedCore\Exceptions\OperationConflictException;
use PayplugUnifiedCore\Exceptions\PartialCancellationNotAllowedException;
use PayplugUnifiedCore\Exceptions\PaymentAlreadyCancelledException;
use PayplugUnifiedCore\Exceptions\PaymentAlreadyCapturedException;
use PayplugUnifiedCore\Exceptions\PaymentNotCapturableException;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Exceptions\PaymentNotVoidableException;
use PayplugUnifiedCore\Exceptions\RefundAmountException;
use PayplugUnifiedCore\Output\CancellationOutput;
use PayplugUnifiedCore\Output\CaptureOutput;
use PayplugUnifiedCore\Output\PaymentOutput;
use PayplugUnifiedCore\Utilities\Helpers\Assert;
use PayplugUnifiedCore\Utilities\Helpers\ExecCodeMapper;
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
 *
 * capturePayment() and cancelPayment() capture or void a payment/authorization, full or partial
 * via $amount, following createRefund()'s shape. Error normalization for both is centralized in
 * assertOperationSuccess().
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
    // CAPTURE_PATH/CANCEL_PATH both take a %s id, for capturePayment()/cancelPayment().
    private const CAPTURE_PATH = '/api/payment-gateway/payments/%s/capture';
    private const CANCEL_PATH = '/api/payment-gateway/payments/%s/void';
    // OPERATION_PATH takes a %s id, for getOperation().
    private const OPERATION_PATH = '/processing-operations/operations/public/%s';
    private const HTTP_NOT_FOUND = 404;
    private const HTTP_CONFLICT = 409;
    // Takes a %s id, for getPayment()/createRefund()/assertOperationSuccess().
    private const PAYMENT_NOT_FOUND_MESSAGE = 'Unified API has no payment "%s".';
    // Issuer/scheme refusal bucket, per ExecCodeMapper's execCode convention.
    private const ISSUER_REFUSAL_EXEC_CODE_PREFIX = '4';

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
            throw new PaymentNotFoundException(\sprintf(self::PAYMENT_NOT_FOUND_MESSAGE, $paymentId), self::HTTP_NOT_FOUND);
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
            $isAuthorizationOnly = !$dto->common->capture;
        } elseif ($dto instanceof PaymentDto) {
            PaymentDtoValidator::validate($dto);
            $isAuthorizationOnly = !$dto->common->capture;
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
            $this->extractNestedString($data, 'paymentMethod', 'id'),
            $this->extractTopLevelString($data, 'maxCaptureDate'),
            $this->extractRemainingCapturableAmountAtCreation($data, $isAuthorizationOnly)
        );
    }

    /**
     * At creation time nothing has been captured yet, so this is just the authorized amount
     * itself — "amount" over "requestedAmount", since a partial-authorization response (issuer
     * approves less than requested) makes them differ. Null for a direct payment
     * (capture === true) or when the response has neither field.
     *
     * @param mixed $data the json_decode()'d response body
     */
    private function extractRemainingCapturableAmountAtCreation($data, bool $isAuthorizationOnly): ?int
    {
        if (!$isAuthorizationOnly) {
            return null;
        }

        return $this->extractTopLevelInt($data, 'amount') ?? $this->extractTopLevelInt($data, 'requestedAmount');
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
     * $orderId and $description are both required — confirmed against the real staging API
     * (2026-08-27) by probing each field's absence individually, not merely the GitBook doc: a body
     * carrying only $orderId is rejected with 400 ("The parameter \"description\" is missing."),
     * and a body carrying only $description is rejected with 400 ("The parameter \"orderId\" is
     * missing.").
     *
     * $submerchantExternalId is optional and sent only when non-null and non-empty (a CMS reading
     * an unset value out of its own settings storage yields '' far more often than a real null,
     * and '' is rejected by the API just as a foreign submerchant is). It is a property of the
     * PayPlug UDV/MID configuration for the payment's *currency*, not of any payment method: the
     * EUR configurations require one, the ones used for other currencies have none. That same
     * 2026-08-27 probing concluded the field was universally required — a body carrying $orderId
     * and $description but no $submerchantExternalId was rejected with 400 ("The parameter
     * \"subMerchantExternalId\" is missing.") — but it only ever ran against EUR payments, whose
     * configuration does own a submerchant and whose refund therefore has to name the same one.
     * Refunding a payment made under a non-EUR configuration instead fails with 400 ("Invalid
     * parameter.") when the key is sent, and succeeds when it is omitted (staging, 2026-09-04).
     * So the rule is per-configuration, and the refund must mirror the payment it refunds — which
     * is what "optional, mirroring CommonFieldsDto" now expresses. When it is sent, the API
     * validates the lower-case "submerchantExternalId" key despite its own error text capitalizing
     * it (confirmed empirically: the capitalized key does not satisfy the check).
     *
     * $currency is likewise optional and sent only when non-null and non-empty. The endpoint
     * carried no currency at all before 2026-09-04, so an $amount travelled bare and the platform
     * had to infer what those minor units meant — harmless while every payment was EUR, but
     * ambiguous for a multi-currency merchant. Unlike $submerchantExternalId, an empty string here
     * is never a meaningful value (every payment has a currency); it is treated as null purely so
     * a caller that failed to resolve one falls back to that same already-supported "let the
     * platform infer" mode instead of putting "" on the wire for the API to reject. Caveat on the evidence: the 2026-09-04 staging run that first
     * succeeded changed both this and $submerchantExternalId at once, so which of the two the
     * earlier "Invalid parameter." referred to was never isolated.
     *
     * @return array{status: int, body: string}
     * @throws InvalidRefundRequestException if $orderId or $description is empty — checked locally
     *                                       before any HTTP call, since ApiException carries only
     *                                       the HTTP status, not the API's own response body
     *                                       naming which field was missing.
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
        ?string $submerchantExternalId = null,
        ?int $amount = null,
        ?string $currency = null
    ): array {
        Assert::notEmpty($orderId, 'orderId', InvalidRefundRequestException::class);
        Assert::notEmpty($description, 'description', InvalidRefundRequestException::class);

        if ($amount !== null) {
            Assert::positive($amount, 'amount', RefundAmountException::class);
        }

        $url = $this->baseUrl . \sprintf(self::REFUND_PATH, rawurlencode($operationId));

        $body = [
            'account' => ['id' => $accountId],
            'orderId' => $orderId,
            'description' => $description,
        ];

        if ($submerchantExternalId !== null && $submerchantExternalId !== '') {
            $body['submerchantExternalId'] = $submerchantExternalId;
        }

        if ($amount !== null) {
            $body['amount'] = $amount;
        }

        if ($currency !== null && $currency !== '') {
            $body['currency'] = $currency;
        }

        $response = $this->sendPostJson($url, $body);

        if ($response['status'] === self::HTTP_NOT_FOUND) {
            throw new PaymentNotFoundException(\sprintf(self::PAYMENT_NOT_FOUND_MESSAGE, $operationId), self::HTTP_NOT_FOUND);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ApiException(\sprintf('Unified API refund request failed with HTTP status %d.', $response['status']), $response['status']);
        }

        return $response;
    }

    /**
     * Captures a payment or authorization, in full or, when $amount is given, in part. Whether a
     * second (or later) call succeeds against the same authorization depends on the account/
     * processor supporting multiple captures — this method itself places no limit on how many
     * times it can be called. $orderId/$description/$amount follow createRefund()'s shape.
     * $currency is required by the API whenever $amount is given (a partial capture) and is sent
     * only when non-null and non-empty, same as createRefund().
     *
     * @throws InvalidCaptureRequestException if $orderId or $description is empty.
     * @throws CaptureAmountException if $amount is given and is zero or negative.
     * @throws PaymentNotFoundException on HTTP 404.
     * @throws OperationConflictException on HTTP 409.
     * @throws MultipleCaptureNotAllowedException if a capture is rejected as a duplicate on an
     *                      authorization the account/processor does not allow capturing more
     *                      than once.
     * @throws AuthorizationExpiredException|PaymentNotCapturableException|AmountExceedsAvailableException
     *                      see assertOperationSuccess().
     * @throws CardOperationException if the issuer refused the capture.
     * @throws ApiException fallback for any other non-2xx status or malformed response.
     */
    public function capturePayment(
        string $paymentId,
        string $accountId,
        string $orderId,
        string $description,
        ?int $amount = null,
        ?string $extraData = null,
        ?string $currency = null
    ): CaptureOutput {
        Assert::notEmpty($orderId, 'orderId', InvalidCaptureRequestException::class);
        Assert::notEmpty($description, 'description', InvalidCaptureRequestException::class);

        if ($amount !== null) {
            Assert::positive($amount, 'amount', CaptureAmountException::class);
        }

        $url = $this->baseUrl . \sprintf(self::CAPTURE_PATH, rawurlencode($paymentId));

        $body = [
            'account' => ['id' => $accountId],
            'orderId' => $orderId,
            'description' => $description,
        ];

        if ($amount !== null) {
            $body['amount'] = $amount;
        }

        if ($currency !== null && $currency !== '') {
            $body['currency'] = $currency;
        }

        if ($extraData !== null && $extraData !== '') {
            $body['extraData'] = $extraData;
        }

        $response = $this->sendPostJson($url, $body);

        $this->assertOperationSuccess($response, $paymentId, 'capture', false);

        $data = json_decode($response['body'], true);

        return new CaptureOutput(
            $response['status'],
            $response['body'],
            $this->extractTopLevelInt($data, 'amount'),
            $this->extractTopLevelInt($data, 'requestedAmount'),
            $this->extractTopLevelString($data, 'maxCaptureDate')
        );
    }

    /**
     * Cancels (voids) a payment or authorization, in full by default or, when $amount is given, in
     * part. $orderId/$description/$amount follow createRefund()'s shape. Omitting $amount only
     * releases the full authorization when nothing has been captured against it yet; once any
     * capture has occurred, the API requires $amount to be the exact remaining
     * authorized-but-uncaptured balance and rejects an omitted or mismatched one. $currency is
     * required by the API whenever $amount is given and is sent only when non-null and
     * non-empty, same as createRefund().
     *
     * @throws InvalidCancellationRequestException if $orderId or $description is empty.
     * @throws CancellationAmountException if $amount is given and is zero or negative.
     * @throws PaymentNotFoundException on HTTP 404.
     * @throws OperationConflictException on HTTP 409.
     * @throws PartialCancellationNotAllowedException if partial cancellation isn't enabled on
     *                      this account's contract.
     * @throws AuthorizationExpiredException|PaymentAlreadyCancelledException|PaymentNotVoidableException|AmountExceedsAvailableException
     *                      see assertOperationSuccess().
     * @throws CardOperationException if the issuer refused the cancellation.
     * @throws ApiException fallback for any other non-2xx status or malformed response.
     */
    public function cancelPayment(
        string $paymentId,
        string $accountId,
        string $orderId,
        string $description,
        ?int $amount = null,
        ?string $extraData = null,
        ?string $currency = null
    ): CancellationOutput {
        Assert::notEmpty($orderId, 'orderId', InvalidCancellationRequestException::class);
        Assert::notEmpty($description, 'description', InvalidCancellationRequestException::class);

        if ($amount !== null) {
            Assert::positive($amount, 'amount', CancellationAmountException::class);
        }

        $url = $this->baseUrl . \sprintf(self::CANCEL_PATH, rawurlencode($paymentId));

        $body = [
            'account' => ['id' => $accountId],
            'orderId' => $orderId,
            'description' => $description,
        ];

        if ($amount !== null) {
            $body['amount'] = $amount;
        }

        if ($currency !== null && $currency !== '') {
            $body['currency'] = $currency;
        }

        if ($extraData !== null && $extraData !== '') {
            $body['extraData'] = $extraData;
        }

        $response = $this->sendPostJson($url, $body);

        $this->assertOperationSuccess($response, $paymentId, 'cancellation', $amount !== null);

        $data = json_decode($response['body'], true);

        return new CancellationOutput(
            $response['status'],
            $response['body'],
            $this->extractTopLevelInt($data, 'amount'),
            $this->extractTopLevelInt($data, 'requestedAmount')
        );
    }

    /**
     * Shared capturePayment()/cancelPayment() error normalization. A 2xx HTTP status alone is not
     * enough to consider the operation successful: the Unified API can return HTTP 200 with a
     * non-"0000" execCode (e.g. a duplicate/replayed request) — that must still be treated as a
     * failure and classified, not returned to the caller as a successful CaptureOutput/
     * CancellationOutput. Checked in order: 404 → not found; 409 → conflict; "message" keyword
     * match → duplicate / expired / already captured / not voidable / not capturable / already
     * cancelled / amount exceeded; execCode "4XXX" → issuer refusal; partial cancel rejected as
     * errorCategory INVALID_REQUEST → not allowed; else generic ApiException. "not voidable"/
     * "not capturable" each map to their own operation-specific exception rather than
     * PaymentAlreadyCapturedException/PaymentAlreadyCancelledException: the same message is
     * returned whether the payment was already captured or already cancelled, so it cannot be
     * used to distinguish those two causes. A "duplicate" message during a capture maps to
     * MultipleCaptureNotAllowedException rather than OperationConflictException: on an
     * authorization the account/processor does not allow capturing more than once, every capture
     * after the first is rejected this way regardless of amount, orderId, or description, so it
     * reflects "this authorization only supports one capture" rather than a literal replay of an
     * identical request. The same message during a cancellation still maps to
     * OperationConflictException.
     *
     * @param array{status: int, body: string} $response
     * @throws PaymentNotFoundException
     * @throws OperationConflictException
     * @throws MultipleCaptureNotAllowedException
     * @throws CardOperationException
     * @throws PartialCancellationNotAllowedException
     * @throws AuthorizationExpiredException
     * @throws PaymentAlreadyCapturedException
     * @throws PaymentAlreadyCancelledException
     * @throws PaymentNotVoidableException
     * @throws PaymentNotCapturableException
     * @throws AmountExceedsAvailableException
     * @throws ApiException
     */
    private function assertOperationSuccess(array $response, string $paymentId, string $operationLabel, bool $isPartialCancelAttempt): void
    {
        if ($response['status'] === self::HTTP_NOT_FOUND) {
            throw new PaymentNotFoundException(\sprintf(self::PAYMENT_NOT_FOUND_MESSAGE, $paymentId), self::HTTP_NOT_FOUND);
        }

        if ($response['status'] === self::HTTP_CONFLICT) {
            throw new OperationConflictException(
                \sprintf('Unified API %s request for payment "%s" conflicted with HTTP status %d.', $operationLabel, $paymentId, self::HTTP_CONFLICT),
                self::HTTP_CONFLICT
            );
        }

        $data = json_decode($response['body'], true);
        $execCode = $this->extractTopLevelString($data, 'execCode');
        $isHttpSuccess = $response['status'] >= 200 && $response['status'] < 300;
        $isExecCodeSuccess = $execCode === null || ExecCodeMapper::toPaymentOutcome($execCode) === PaymentOutcome::PAID;

        if ($isHttpSuccess && $isExecCodeSuccess) {
            return;
        }

        $message = $this->extractTopLevelString($data, 'message');

        $this->throwForMessageKeyword($message, $operationLabel, $response['status']);

        if ($execCode !== null && strpos($execCode, self::ISSUER_REFUSAL_EXEC_CODE_PREFIX) === 0) {
            throw new CardOperationException(
                $message ?? \sprintf('Unified API %s request for payment "%s" was refused by the issuer (execCode "%s").', $operationLabel, $paymentId, $execCode),
                $response['status']
            );
        }

        $errorCategory = $this->extractTopLevelString($data, 'errorCategory');

        if ($isPartialCancelAttempt && $errorCategory === 'INVALID_REQUEST') {
            throw new PartialCancellationNotAllowedException(
                $message ?? \sprintf('Partial cancellation is not enabled on the contract for payment "%s".', $paymentId),
                $response['status']
            );
        }

        throw new ApiException(
            \sprintf(
                'Unified API %s request for payment "%s" failed with HTTP status %d%s.',
                $operationLabel,
                $paymentId,
                $response['status'],
                $execCode !== null ? \sprintf(' (execCode "%s")', $execCode) : ''
            ),
            $response['status']
        );
    }

    /**
     * The "message" keyword half of assertOperationSuccess()'s classification, factored out to
     * keep that method's cognitive complexity in check. A no-op when $message is null — the
     * caller falls through to its own execCode/errorCategory checks and, ultimately, the generic
     * ApiException.
     *
     * @throws OperationConflictException
     * @throws MultipleCaptureNotAllowedException
     * @throws AuthorizationExpiredException
     * @throws PaymentAlreadyCapturedException
     * @throws PaymentNotVoidableException
     * @throws PaymentNotCapturableException
     * @throws PaymentAlreadyCancelledException
     * @throws AmountExceedsAvailableException
     */
    private function throwForMessageKeyword(?string $message, string $operationLabel, int $status): void
    {
        if ($message === null) {
            return;
        }

        $lowerMessage = strtolower($message);

        if (strpos($lowerMessage, 'duplicate') !== false) {
            if ($operationLabel === 'capture') {
                throw new MultipleCaptureNotAllowedException($message, $status);
            }

            throw new OperationConflictException($message, $status);
        }

        if (strpos($lowerMessage, 'expired') !== false) {
            throw new AuthorizationExpiredException($message, $status);
        }

        if (strpos($lowerMessage, 'already') !== false && strpos($lowerMessage, 'captur') !== false) {
            throw new PaymentAlreadyCapturedException($message, $status);
        }

        if (strpos($lowerMessage, 'not voidable') !== false) {
            throw new PaymentNotVoidableException($message, $status);
        }

        if (strpos($lowerMessage, 'not capturable') !== false) {
            throw new PaymentNotCapturableException($message, $status);
        }

        if (strpos($lowerMessage, 'already') !== false && (strpos($lowerMessage, 'cancel') !== false || strpos($lowerMessage, 'void') !== false)) {
            throw new PaymentAlreadyCancelledException($message, $status);
        }

        if (strpos($lowerMessage, 'exceed') !== false) {
            throw new AmountExceedsAvailableException($message, $status);
        }
    }

    /**
     * Reads a top-level string field out of the already-decoded response body. Same
     * null-on-anything-unexpected reasoning as extractNestedString().
     *
     * @param mixed $data the json_decode()'d response body
     */
    private function extractTopLevelString($data, string $key): ?string
    {
        if (!\is_array($data) || !isset($data[$key]) || !\is_string($data[$key])) {
            return null;
        }

        return $data[$key];
    }

    /**
     * Reads a top-level integer field out of the already-decoded response body. Same reasoning as
     * extractTopLevelString().
     *
     * @param mixed $data the json_decode()'d response body
     */
    private function extractTopLevelInt($data, string $key): ?int
    {
        if (!\is_array($data) || !isset($data[$key]) || !\is_int($data[$key])) {
            return null;
        }

        return $data[$key];
    }
}
