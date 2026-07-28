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
        $cached = $this->tokenCache->get(self::CACHE_KEY_PREFIX . $clientId);

        if ($cached !== null) {
            return $cached;
        }

        return $this->mintAndCache($clientId, $clientSecret);
    }

    /**
     * Discards the cached token for $clientId and mints a replacement. For callers that hold a
     * token the API just rejected: RENEWAL_MARGIN_SECONDS only covers a token aging out, not one
     * invalidated early (rotated client secret, revoked grant, changed permissions, or clock skew
     * wider than the margin), and without this a single poisoned cache entry would fail every
     * call until its TTL runs out.
     *
     * @throws ApiException if minting the replacement fails
     */
    public function refreshToken(string $clientId, string $clientSecret): string
    {
        // Dropped before the mint attempt, not merely overwritten after it: if minting throws, the
        // rejected token must still be gone rather than left behind to be replayed.
        $this->tokenCache->delete(self::CACHE_KEY_PREFIX . $clientId);

        return $this->mintAndCache($clientId, $clientSecret);
    }

    /**
     * @throws ApiException
     */
    private function mintAndCache(string $clientId, string $clientSecret): string
    {
        $token = $this->oauth2Client->getClientCredentialsToken($clientId, $clientSecret);
        $ttl = max(1, $token->expiresIn - self::RENEWAL_MARGIN_SECONDS);
        $this->tokenCache->set(self::CACHE_KEY_PREFIX . $clientId, $token->accessToken, $ttl);

        return $token->accessToken;
    }
}
