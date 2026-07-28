<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Auth;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Exceptions\ApiException;

final class TokenManagerTest extends MockeryTestCase
{
    public function testGetValidTokenReturnsCachedTokenWithoutCallingHttpClient(): void
    {
        $tokenCache = Mockery::mock(ITokenCache::class);
        $tokenCache->shouldReceive('get')->once()->with('upc_oauth_token:client_abc')->andReturn('cached-jwt');

        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $httpClient->shouldNotReceive('post');

        $oauth2Client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');
        $tokenManager = new TokenManager($tokenCache, $oauth2Client);

        self::assertSame('cached-jwt', $tokenManager->getValidToken('client_abc', 'secret_xyz'));
    }

    public function testGetValidTokenFetchesAndCachesANewTokenOnCacheMiss(): void
    {
        $tokenCache = Mockery::mock(ITokenCache::class);
        $tokenCache->shouldReceive('get')->once()->with('upc_oauth_token:client_abc')->andReturn(null);
        $tokenCache->shouldReceive('set')->once()->with('upc_oauth_token:client_abc', 'fresh-jwt', 240);

        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $httpClient->shouldReceive('post')->once()->andReturn([
            'status' => 200,
            'body' => json_encode(['access_token' => 'fresh-jwt', 'expires_in' => 300, 'token_type' => 'Bearer']),
        ]);

        $oauth2Client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');
        $tokenManager = new TokenManager($tokenCache, $oauth2Client);

        self::assertSame('fresh-jwt', $tokenManager->getValidToken('client_abc', 'secret_xyz'));
    }

    public function testGetValidTokenFloorsTtlAtOneSecondWhenExpiresInIsSmallerThanTheRenewalMargin(): void
    {
        $tokenCache = Mockery::mock(ITokenCache::class);
        $tokenCache->shouldReceive('get')->once()->with('upc_oauth_token:client_abc')->andReturn(null);
        $tokenCache->shouldReceive('set')->once()->with('upc_oauth_token:client_abc', 'short-lived-jwt', 1);

        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $httpClient->shouldReceive('post')->once()->andReturn([
            'status' => 200,
            'body' => json_encode(['access_token' => 'short-lived-jwt', 'expires_in' => 30, 'token_type' => 'Bearer']),
        ]);

        $oauth2Client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');
        $tokenManager = new TokenManager($tokenCache, $oauth2Client);

        $tokenManager->getValidToken('client_abc', 'secret_xyz');
    }

    public function testRefreshTokenDiscardsTheCachedEntryAndMintsAFreshOneWithoutReadingTheCache(): void
    {
        $tokenCache = Mockery::mock(ITokenCache::class);
        $tokenCache->shouldNotReceive('get');
        $tokenCache->shouldReceive('delete')->once()->with('upc_oauth_token:client_abc');
        $tokenCache->shouldReceive('set')->once()->with('upc_oauth_token:client_abc', 'fresh-jwt', 240);

        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $httpClient->shouldReceive('post')->once()->andReturn([
            'status' => 200,
            'body' => json_encode(['access_token' => 'fresh-jwt', 'expires_in' => 300, 'token_type' => 'Bearer']),
        ]);

        $oauth2Client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');
        $tokenManager = new TokenManager($tokenCache, $oauth2Client);

        self::assertSame('fresh-jwt', $tokenManager->refreshToken('client_abc', 'secret_xyz'));
    }

    /**
     * The delete happens before the mint attempt on purpose: if minting fails, the rejected token
     * must still be gone, or every later call keeps replaying it until its TTL runs out.
     */
    public function testRefreshTokenStillDiscardsTheCachedEntryWhenMintingFails(): void
    {
        $tokenCache = Mockery::mock(ITokenCache::class);
        $tokenCache->shouldReceive('delete')->once()->with('upc_oauth_token:client_abc');
        $tokenCache->shouldNotReceive('set');

        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $httpClient->shouldReceive('post')->once()->andReturn(['status' => 503, 'body' => '{"error":"unavailable"}']);

        $oauth2Client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');
        $tokenManager = new TokenManager($tokenCache, $oauth2Client);

        $this->expectException(ApiException::class);
        $tokenManager->refreshToken('client_abc', 'secret_xyz');
    }
}
