<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Auth;

use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidTokenException;
use PayplugUnifiedCore\Models\AuthorizationRequest;
use PayplugUnifiedCore\Models\Token;
use PayplugUnifiedCore\Utilities\Helpers\PkceHelper;

/**
 * Pure OAuth2/PKCE token mechanics against the identity provider — no caching (see TokenManager
 * for that).
 * $baseUrl is supplied by the caller (e.g. "https://api.payplug.com" or its -qa equivalent) so
 * switching environments is a constructor argument, not a build-time constant swap.
 */
final class OAuth2Client
{
    private const AUTHORIZATION_PATH = '/oauth2/auth';
    private const TOKEN_PATH = '/oauth2/token';

    /** @var IOAuthHttpClient */
    private $httpClient;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $redirectUri;

    /** @var string */
    private $scope;

    /** @var string */
    private $audience;

    public function __construct(IOAuthHttpClient $httpClient, string $baseUrl, string $redirectUri, string $scope, string $audience)
    {
        $this->httpClient = $httpClient;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->redirectUri = $redirectUri;
        $this->scope = $scope;
        $this->audience = $audience;
    }

    /**
     * Builds the authorization-code+PKCE redirect URL. Does not redirect itself (no header()
     * call) — the caller performs the actual HTTP redirect.
     */
    public function buildAuthorizationUrl(string $clientId): AuthorizationRequest
    {
        $codeVerifier = PkceHelper::generateCodeVerifier();
        $codeChallenge = PkceHelper::deriveCodeChallenge($codeVerifier);
        $state = PkceHelper::generateState();

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'state' => $state,
            'audience' => $this->audience,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return new AuthorizationRequest($this->baseUrl . self::AUTHORIZATION_PATH . '?' . $query, $state, $codeVerifier);
    }

    /**
     * @throws ApiException if the exchange fails or the response is malformed
     */
    public function exchangeAuthorizationCode(string $clientId, string $code, string $codeVerifier): Token
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * client_secret_basic auth (not body params) and audience match the identity provider's
     * client-credentials registration for this grant type exactly.
     *
     * @throws ApiException if the exchange fails or the response is malformed
     */
    public function getClientCredentialsToken(string $clientId, string $clientSecret): Token
    {
        return $this->requestToken(
            [
                'grant_type' => 'client_credentials',
                'audience' => $this->audience,
            ],
            ['Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret)]
        );
    }

    /**
     * @param array<string, string> $formParams
     * @param array<string, string> $extraHeaders
     * @throws ApiException
     */
    private function requestToken(array $formParams, array $extraHeaders = []): Token
    {
        $headers = $extraHeaders + ['Content-Type' => 'application/x-www-form-urlencoded'];
        $response = $this->httpClient->post($this->baseUrl . self::TOKEN_PATH, $formParams, $headers);

        // @phpstan-ignore-next-line isset.offset (IOAuthHttpClient's array shape is a docblock contract, not enforceable at runtime against a misbehaving implementation)
        if (!isset($response['status'], $response['body'])) {
            throw new ApiException('OAuth2 HTTP client response is malformed.');
        }

        $status = (int) $response['status'];
        $body = (string) $response['body'];

        if ($status < 200 || $status >= 300) {
            throw new ApiException(\sprintf('OAuth2 token request failed with HTTP status %d.', $status));
        }

        $data = json_decode($body, true);

        if (!\is_array($data) || !isset($data['access_token'], $data['expires_in'], $data['token_type'])) {
            throw new ApiException('OAuth2 token response is malformed.');
        }

        try {
            return new Token((string) $data['access_token'], (int) $data['expires_in'], (string) $data['token_type']);
        } catch (InvalidTokenException $e) {
            throw new ApiException('OAuth2 token response is malformed.', 0, $e);
        }
    }
}
