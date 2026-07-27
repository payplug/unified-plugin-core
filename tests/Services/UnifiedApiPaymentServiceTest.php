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
use PayplugUnifiedCore\Services\UnifiedApiPaymentService;

final class UnifiedApiPaymentServiceTest extends MockeryTestCase
{
    public function testGetPaymentReturnsStatusAndBodyOnSuccess(): void
    {
        $body = json_encode(['id' => 'pay_123']);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')
            ->once()
            ->with('https://api.payplug.com/payments/pay_123', ['Authorization' => 'Bearer cached-jwt'])
            ->andReturn(['status' => 200, 'body' => $body]);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => 200, 'body' => $body], $service->getPayment('pay_123'));
    }

    public function testGetPaymentUrlEncodesThePaymentId(): void
    {
        $body = json_encode(['id' => 'pay/123 456']);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')
            ->once()
            ->with('https://api.payplug.com/payments/pay%2F123%20456', ['Authorization' => 'Bearer cached-jwt'])
            ->andReturn(['status' => 200, 'body' => $body]);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => 200, 'body' => $body], $service->getPayment('pay/123 456'));
    }

    public function testGetPaymentThrowsApiExceptionOnNonSuccessStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 404, 'body' => '{"error":"not_found"}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $service->getPayment('pay_123');
    }

    public function testGetPaymentThrowsApiExceptionOnMalformedResponse(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 200]); // missing 'body'

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $service->getPayment('pay_123');
    }

    private function makeTokenManager(): TokenManager
    {
        $tokenCache = Mockery::mock(ITokenCache::class);
        $tokenCache->shouldReceive('get')->once()->with('upc_oauth_token:client_abc')->andReturn('cached-jwt');

        $oauthHttpClient = Mockery::mock(IOAuthHttpClient::class);
        $oauthHttpClient->shouldNotReceive('post');

        $oauth2Client = new OAuth2Client($oauthHttpClient, 'https://idp.example.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        return new TokenManager($tokenCache, $oauth2Client);
    }
}
