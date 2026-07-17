<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Auth;

use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Exceptions\ApiException;

/**
 * Caching wrapper around OAuth2Client's client-credentials flow, for background API calls.
 * Caches with a TTL shorter than the token's actual expiresIn (a fixed safety buffer) so a
 * request never receives a token that's about to die mid-flight.
 */
final class TokenManager
{
    private const CACHE_KEY_PREFIX = 'upc_oauth_token:';
    private const RENEWAL_MARGIN_SECONDS = 60;

    /** @var ITokenCache */
    private $tokenCache;

    /** @var OAuth2Client */
    private $oauth2Client;

    public function __construct(ITokenCache $tokenCache, OAuth2Client $oauth2Client)
    {
        $this->tokenCache = $tokenCache;
        $this->oauth2Client = $oauth2Client;
    }

    /**
     * @throws ApiException if a refresh is needed and fails
     */
    public function getValidToken(string $clientId, string $clientSecret): string
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $clientId;
        $cached = $this->tokenCache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $token = $this->oauth2Client->getClientCredentialsToken($clientId, $clientSecret);
        $ttl = max(1, $token->expiresIn - self::RENEWAL_MARGIN_SECONDS);
        $this->tokenCache->set($cacheKey, $token->accessToken, $ttl);

        return $token->accessToken;
    }
}
