<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Services;

use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;

/**
 * Fetches a payment from the Unified API, authenticated via TokenManager's client-credentials
 * JWT. Returns the raw HTTP response (status + body), not a parsed payment model — the full
 * payment data model is separate, future scope.
 */
final class UnifiedApiPaymentService
{
    private const PAYMENT_PATH = '/payments/%s';
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_NOT_FOUND = 404;

    /** @var IUnifiedApiHttpClient */
    private $httpClient;

    /** @var TokenManager */
    private $tokenManager;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    public function __construct(
        IUnifiedApiHttpClient $httpClient,
        TokenManager $tokenManager,
        string $baseUrl,
        string $clientId,
        string $clientSecret
    ) {
        $this->httpClient = $httpClient;
        $this->tokenManager = $tokenManager;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

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

        $response = $this->send($url, $this->tokenManager->getValidToken($this->clientId, $this->clientSecret));

        // A cached JWT can be rejected while still inside its cache TTL, so a 401 is retried once
        // with a freshly minted token instead of being reported straight back: otherwise one
        // poisoned cache entry fails every payment lookup until its TTL expires. Bounded to a
        // single retry — a 401 on a token minted seconds ago is a credentials/permissions problem
        // that retrying cannot fix.
        if ($response['status'] === self::HTTP_UNAUTHORIZED) {
            $response = $this->send($url, $this->tokenManager->refreshToken($this->clientId, $this->clientSecret));
        }

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
     * @throws ApiException if the response does not match IUnifiedApiHttpClient's documented shape
     */
    private function send(string $url, string $accessToken): array
    {
        $response = $this->httpClient->get($url, ['Authorization' => 'Bearer ' . $accessToken]);

        // @phpstan-ignore-next-line isset.offset (IUnifiedApiHttpClient's array shape is a docblock contract, not enforceable at runtime against a misbehaving implementation)
        if (!isset($response['status'], $response['body'])) {
            throw new ApiException('Unified API HTTP client response is malformed.');
        }

        return ['status' => (int) $response['status'], 'body' => (string) $response['body']];
    }
}
