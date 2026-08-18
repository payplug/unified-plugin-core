<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Services;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\ITokenCache;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\OperationNotFoundException;
use PayplugUnifiedCore\Services\UnifiedApiOperationService;

final class UnifiedApiOperationServiceTest extends MockeryTestCase
{
    public function testGetOperationReturnsStatusAndBodyOnSuccess(): void
    {
        $body = json_encode(['id' => 'op_123', 'transaction' => ['status' => ['execCode' => '0000']]]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')
            ->once()
            ->with('https://api.payplug.com/processing-operations/operations/op_123', ['Authorization' => 'Bearer cached-jwt'])
            ->andReturn(['status' => 200, 'body' => $body]);

        $service = new UnifiedApiOperationService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => 200, 'body' => $body], $service->getOperation('op_123'));
    }

    public function testGetOperationUrlEncodesTheOperationId(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')
            ->once()
            ->with('https://api.payplug.com/processing-operations/operations/op%2F123%20456', ['Authorization' => 'Bearer cached-jwt'])
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = new UnifiedApiOperationService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => 200, 'body' => '{}'], $service->getOperation('op/123 456'));
    }

    public function testGetOperationNormalizesATrailingSlashOnTheBaseUrl(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')
            ->once()
            ->with('https://api.payplug.com/processing-operations/operations/op_123', ['Authorization' => 'Bearer cached-jwt'])
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = new UnifiedApiOperationService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com/', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => 200, 'body' => '{}'], $service->getOperation('op_123'));
    }

    public function testGetOperationThrowsApiExceptionOnNonSuccessStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 500, 'body' => '{"error":"boom"}']);

        $service = new UnifiedApiOperationService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API operation request failed with HTTP status 500.');
        $this->expectExceptionCode(500);
        $service->getOperation('op_123');
    }

    public function testGetOperationThrowsOperationNotFoundExceptionOnA404(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 404, 'body' => '{"error":"not_found"}']);

        $service = new UnifiedApiOperationService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(OperationNotFoundException::class);
        $this->expectExceptionMessage('Unified API has no operation "op_123".');
        $this->expectExceptionCode(404);
        $service->getOperation('op_123');
    }

    /**
     * OperationNotFoundException is a sibling of ApiException, not a subclass, so a consumer
     * catching ApiException does NOT catch a missing operation.
     */
    public function testOperationNotFoundIsNotCaughtAsAnApiException(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 404, 'body' => '{"error":"not_found"}']);

        $service = new UnifiedApiOperationService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        try {
            $service->getOperation('op_123');
            self::fail('Expected an OperationNotFoundException.');
        } catch (ApiException $e) {
            self::fail('A 404 must not be catchable as ApiException.');
        } catch (OperationNotFoundException $e) {
            self::assertSame(404, $e->getCode());
        }
    }

    public function testGetOperationRetriesOnceWithAFreshTokenWhenTheCachedOneIsRejected(): void
    {
        $body = json_encode(['id' => 'op_123']);
        $url = 'https://api.payplug.com/processing-operations/operations/op_123';

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')
            ->once()
            ->with($url, ['Authorization' => 'Bearer stale-jwt'])
            ->andReturn(['status' => 401, 'body' => '{"error":"invalid_token"}']);
        $httpClient->shouldReceive('get')
            ->once()
            ->with($url, ['Authorization' => 'Bearer fresh-jwt'])
            ->andReturn(['status' => 200, 'body' => $body]);

        $service = new UnifiedApiOperationService($httpClient, $this->makeTokenManagerExpectingRefresh(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => 200, 'body' => $body], $service->getOperation('op_123'));
    }

    private function makeTokenManager(): TokenManager
    {
        $tokenCache = Mockery::mock(ITokenCache::class);
        $tokenCache->shouldReceive('get')->once()->with('upc_oauth_token:client_abc')->andReturn('cached-jwt');
        $tokenCache->shouldNotReceive('delete');

        $oauthHttpClient = Mockery::mock(IOAuthHttpClient::class);
        $oauthHttpClient->shouldNotReceive('post');

        $oauth2Client = new OAuth2Client($oauthHttpClient, 'https://idp.example.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        return new TokenManager($tokenCache, $oauth2Client);
    }

    /**
     * Cache holds a token the Unified API will reject, so the service is expected to drop it and
     * mint a replacement exactly once.
     */
    private function makeTokenManagerExpectingRefresh(): TokenManager
    {
        $tokenCache = Mockery::mock(ITokenCache::class);
        $tokenCache->shouldReceive('get')->once()->with('upc_oauth_token:client_abc')->andReturn('stale-jwt');
        $tokenCache->shouldReceive('delete')->once()->with('upc_oauth_token:client_abc');
        $tokenCache->shouldReceive('set')->once()->with('upc_oauth_token:client_abc', 'fresh-jwt', 240);

        $oauthHttpClient = Mockery::mock(IOAuthHttpClient::class);
        $oauthHttpClient->shouldReceive('post')->once()->andReturn([
            'status' => 200,
            'body' => json_encode(['access_token' => 'fresh-jwt', 'expires_in' => 300, 'token_type' => 'Bearer']),
        ]);

        $oauth2Client = new OAuth2Client($oauthHttpClient, 'https://idp.example.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        return new TokenManager($tokenCache, $oauth2Client);
    }
}
