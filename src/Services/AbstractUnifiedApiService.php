<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Services;

use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Exceptions\ApiException;

/**
 * Shared Unified API request mechanics for every Services/ class: resolves a client-credentials
 * JWT via TokenManager, retries exactly once on a 401 with a freshly minted token (a cached JWT can
 * be rejected while still inside its cache TTL), and normalizes IUnifiedApiHttpClient's response
 * shape. Extracted once UnifiedApiPaymentService (PRE-3576) got a sibling
 * (UnifiedApiHostedPaymentService, PRE-3587) rather than duplicating this a third time, per this
 * library's own precedent (see CLAUDE.md's Services/ section).
 */
abstract class AbstractUnifiedApiService
{
    private const HTTP_UNAUTHORIZED = 401;

    /** @var IUnifiedApiHttpClient */
    protected $httpClient;

    /** @var TokenManager */
    protected $tokenManager;

    /** @var string */
    protected $baseUrl;

    /** @var string */
    protected $clientId;

    /** @var string */
    protected $clientSecret;

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
     * @throws ApiException if the response does not match IUnifiedApiHttpClient's documented shape
     */
    protected function sendGet(string $url): array
    {
        return $this->sendWithRetry(function (string $accessToken) use ($url): array {
            return $this->httpClient->get($url, ['Authorization' => 'Bearer ' . $accessToken]);
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: string}
     * @throws ApiException if the response does not match IUnifiedApiHttpClient's documented shape
     */
    protected function sendPostJson(string $url, array $body): array
    {
        return $this->sendWithRetry(function (string $accessToken) use ($url, $body): array {
            return $this->httpClient->postJson($url, $body, [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ]);
        });
    }

    /**
     * @param callable(string): array{status?: mixed, body?: mixed} $request
     * @return array{status: int, body: string}
     * @throws ApiException
     */
    private function sendWithRetry(callable $request): array
    {
        $response = $this->normalize($request($this->tokenManager->getValidToken($this->clientId, $this->clientSecret)));

        // A cached JWT can be rejected while still inside its cache TTL, so a 401 is retried once
        // with a freshly minted token instead of being reported straight back: otherwise one
        // poisoned cache entry fails every request until its TTL expires. Bounded to a single
        // retry — a 401 on a token minted seconds ago is a credentials/permissions problem that
        // retrying cannot fix.
        if ($response['status'] === self::HTTP_UNAUTHORIZED) {
            $response = $this->normalize($request($this->tokenManager->refreshToken($this->clientId, $this->clientSecret)));
        }

        return $response;
    }

    /**
     * @param array{status?: mixed, body?: mixed} $response
     * @return array{status: int, body: string}
     * @throws ApiException
     */
    private function normalize(array $response): array
    {
        if (!isset($response['status'], $response['body'])) {
            throw new ApiException('Unified API HTTP client response is malformed.');
        }

        return ['status' => (int) $response['status'], 'body' => (string) $response['body']];
    }
}
