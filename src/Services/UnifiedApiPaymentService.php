<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Services;

use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Exceptions\ApiException;

/**
 * Fetches a payment from the Unified API, authenticated via TokenManager's client-credentials
 * JWT. Returns the raw HTTP response (status + body), not a parsed payment model — the full
 * payment data model is separate, future scope.
 */
final class UnifiedApiPaymentService
{
    private const PAYMENT_PATH = '/payments/%s';

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
     * @throws ApiException if the request fails, returns a non-2xx status, or the response is malformed
     */
    public function getPayment(string $paymentId): array
    {
        $accessToken = $this->tokenManager->getValidToken($this->clientId, $this->clientSecret);

        $url = $this->baseUrl . \sprintf(self::PAYMENT_PATH, rawurlencode($paymentId));
        $response = $this->httpClient->get($url, ['Authorization' => 'Bearer ' . $accessToken]);

        // @phpstan-ignore-next-line isset.offset (IUnifiedApiHttpClient's array shape is a docblock contract, not enforceable at runtime against a misbehaving implementation)
        if (!isset($response['status'], $response['body'])) {
            throw new ApiException('Unified API HTTP client response is malformed.');
        }

        $status = (int) $response['status'];
        $body = (string) $response['body'];

        if ($status < 200 || $status >= 300) {
            throw new ApiException(\sprintf('Unified API payment request failed with HTTP status %d.', $status));
        }

        return ['status' => $status, 'body' => $body];
    }
}
