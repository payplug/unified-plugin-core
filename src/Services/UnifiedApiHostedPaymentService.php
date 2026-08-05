<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Services;

use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Models\HostedPaymentResult;

/**
 * Creates/confirms a payment from a hosted-fields token (hfToken) against the Unified API,
 * authenticated via TokenManager's client-credentials JWT — the create-side sibling of
 * UnifiedApiPaymentService (PRE-3576's GetPayment). $accountId is a plain constructor argument
 * (matching UnifiedApiPaymentService's clientId/clientSecret/baseUrl pattern) rather than sourced
 * from IConfigurationRepository, since it identifies the Unified API processing account the
 * payment is created against and has no relationship to the OAuth2 clientId/clientSecret pair.
 *
 * capture is always true: this ticket's method signature has no capture parameter, so every call
 * is an immediate payment, never an authorization-only hold.
 *
 * createHostedPayment()'s optional parameters (browser/customer/description/paymentMethod)
 * were added after cross-checking the Unified API's own OpenAPI schema: only account, amount,
 * paymentMethod and capture are actually required at the request's top level — orderId, hfToken,
 * customer, browser, description, etc. are all optional there. The ticket's own 4-parameter
 * signature (hfToken, amount, currency, orderId) undersold this: it omitted browser entirely, and
 * browser data (ip/referrer/userAgent) is exactly what card networks use to decide whether a 3DS
 * challenge can be skipped (frictionless) instead of always being forced — directly relevant to
 * this ticket's own "3DS-pending vs direct success" deliverable. $browser is left optional (not
 * required) to match the schema and because a CMS may not always have it (e.g. server-to-server
 * background jobs), but callers should pass it whenever a real end-user request is available.
 */
final class UnifiedApiHostedPaymentService extends AbstractUnifiedApiService
{
    private const PAYMENTS_PATH = '/payments';

    /** @var string */
    private $accountId;

    public function __construct(
        IUnifiedApiHttpClient $httpClient,
        TokenManager $tokenManager,
        string $baseUrl,
        string $clientId,
        string $clientSecret,
        string $accountId
    ) {
        parent::__construct($httpClient, $tokenManager, $baseUrl, $clientId, $clientSecret);
        $this->accountId = $accountId;
    }

    /**
     * @param array{ip: string, referrer: string, userAgent: string}|null $browser end-user browser
     *        fingerprint. The Unified API schema requires all three sub-fields together whenever
     *        browser is sent at all — there's no partial form. Omit entirely (null) only when
     *        genuinely unavailable; sending it is what lets the issuer attempt a frictionless
     *        (challenge-free) 3DS flow instead of always forcing one.
     * @param array{id: string, email: string}|null $customer end-user identity; both sub-fields are
     *        required together whenever customer is sent at all.
     * @param array{details?: array{fullName?: string, selectedBrand?: string, validityDate?: string}}|null $paymentMethod
     *        supplementary card metadata (cardholder name, card brand), nested exactly as the
     *        Unified API expects it. All of details' sub-fields are optional: unlike
     *        browser/customer, a real hosted-fields Unified API request has been observed
     *        succeeding with only fullName/selectedBrand and no validityDate, despite the API's own
     *        schema text suggesting otherwise — the concrete example is trusted over that text
     *        here.
     * @param string|null $descriptor label shown on the customer's bank statement
     * @param string|null $notificationUrl webhook URL the Unified API calls with the final outcome
     *        (see PRE-3588's WebhookNotificationHelper on the receiving side)
     * @param string|null $extraData free-form text echoed back verbatim in that notification
     * @throws ApiException if the request fails, returns a non-2xx status, or the response is
     *                      malformed. getCode() carries the HTTP status when one was received,
     *                      and 0 when the client's response shape was unusable.
     */
    public function createHostedPayment(
        string $hfToken,
        int $amount,
        string $currency,
        string $orderId,
        ?array $browser = null,
        ?array $customer = null,
        ?string $description = null,
        ?array $paymentMethod = null,
        ?string $descriptor = null,
        ?string $notificationUrl = null,
        ?string $extraData = null
    ): HostedPaymentResult {
        $url = $this->baseUrl . self::PAYMENTS_PATH;

        $body = [
            'account' => ['id' => $this->accountId],
            'amount' => $amount,
            'currency' => $currency,
            'orderId' => $orderId,
            'capture' => true,
            'hfToken' => $hfToken,
        ];

        if ($paymentMethod !== null) {
            $body['paymentMethod'] = $paymentMethod;
        }

        if ($browser !== null) {
            $body['browser'] = $browser;
        }

        if ($customer !== null) {
            $body['customer'] = $customer;
        }

        if ($description !== null) {
            $body['description'] = $description;
        }

        if ($descriptor !== null) {
            $body['descriptor'] = $descriptor;
        }

        if ($notificationUrl !== null) {
            $body['notificationUrl'] = $notificationUrl;
        }

        if ($extraData !== null) {
            $body['extraData'] = $extraData;
        }

        $response = $this->sendPostJson($url, $body);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ApiException(\sprintf('Unified API hosted payment request failed with HTTP status %d.', $response['status']), $response['status']);
        }

        return new HostedPaymentResult($response['status'], $response['body'], $this->extractRedirectUrl($response['body']));
    }

    /**
     * The presence of a "redirect" object (with a "url" field) in an otherwise-2xx response is the
     * Unified API's own signal that 3DS/SCA authentication is pending — its absence means the
     * payment was processed synchronously. A body that isn't valid JSON, or has no such field,
     * yields null rather than an exception: this method only extracts one derived field, it does
     * not validate the full payment representation (out of scope, same as GetPayment).
     */
    private function extractRedirectUrl(string $body): ?string
    {
        $data = json_decode($body, true);

        if (!\is_array($data) || !isset($data['redirect']['url']) || !\is_string($data['redirect']['url'])) {
            return null;
        }

        return $data['redirect']['url'];
    }
}
