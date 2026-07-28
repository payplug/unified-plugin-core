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
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
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

    public function testGetPaymentNormalizesATrailingSlashOnTheBaseUrl(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        // The exact-URL expectation is the point: a missing rtrim() would produce a double slash.
        $httpClient->shouldReceive('get')
            ->once()
            ->with('https://api.payplug.com/payments/pay_123', ['Authorization' => 'Bearer cached-jwt'])
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com/', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => 200, 'body' => '{}'], $service->getPayment('pay_123'));
    }

    public function testGetPaymentThrowsApiExceptionOnNonSuccessStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 500, 'body' => '{"error":"boom"}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API payment request failed with HTTP status 500.');
        $this->expectExceptionCode(500);
        $service->getPayment('pay_123');
    }

    /**
     * @dataProvider successStatusProvider
     */
    public function testGetPaymentTreatsTheWhole2xxRangeAsSuccess(int $status): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => $status, 'body' => '{}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => $status, 'body' => '{}'], $service->getPayment('pay_123'));
    }

    /**
     * @return array<string, array{int}>
     */
    public function successStatusProvider(): array
    {
        return [
            'inclusive lower bound' => [200],
            'inclusive upper bound' => [299],
        ];
    }

    /**
     * @dataProvider failureStatusProvider
     */
    public function testGetPaymentTreatsStatusesJustOutsideThe2xxRangeAsFailures(int $status): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => $status, 'body' => '{}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionCode($status);
        $service->getPayment('pay_123');
    }

    /**
     * @return array<string, array{int}>
     */
    public function failureStatusProvider(): array
    {
        return [
            'one below the success range' => [199],
            'one above the success range' => [300],
        ];
    }

    public function testGetPaymentThrowsPaymentNotFoundExceptionOnA404(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 404, 'body' => '{"error":"not_found"}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(PaymentNotFoundException::class);
        $this->expectExceptionMessage('Unified API has no payment "pay_123".');
        $this->expectExceptionCode(404);
        $service->getPayment('pay_123');
    }

    /**
     * PaymentNotFoundException is a sibling of ApiException, not a subclass, so a consumer catching
     * ApiException does NOT catch a missing payment. That's deliberate — this test guards it, since
     * re-parenting the exception later would silently change every consumer's catch behavior.
     */
    public function testPaymentNotFoundIsNotCaughtAsAnApiException(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 404, 'body' => '{"error":"not_found"}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        try {
            $service->getPayment('pay_123');
            self::fail('Expected a PaymentNotFoundException.');
        } catch (ApiException $e) {
            self::fail('A 404 must not be catchable as ApiException.');
        } catch (PaymentNotFoundException $e) {
            self::assertSame(404, $e->getCode());
        }
    }

    public function testGetPaymentThrowsApiExceptionWhenTheResponseIsMissingItsBody(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 200]); // missing 'body'

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API HTTP client response is malformed.');
        $service->getPayment('pay_123');
    }

    public function testGetPaymentThrowsApiExceptionWhenTheResponseIsMissingItsStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['body' => '{}']); // missing 'status'

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API HTTP client response is malformed.');
        $service->getPayment('pay_123');
    }

    public function testGetPaymentRetriesOnceWithAFreshTokenWhenTheCachedOneIsRejected(): void
    {
        $body = json_encode(['id' => 'pay_123']);
        $url = 'https://api.payplug.com/payments/pay_123';

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')
            ->once()
            ->with($url, ['Authorization' => 'Bearer stale-jwt'])
            ->andReturn(['status' => 401, 'body' => '{"error":"invalid_token"}']);
        $httpClient->shouldReceive('get')
            ->once()
            ->with($url, ['Authorization' => 'Bearer fresh-jwt'])
            ->andReturn(['status' => 200, 'body' => $body]);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManagerExpectingRefresh(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => 200, 'body' => $body], $service->getPayment('pay_123'));
    }

    public function testGetPaymentThrowsApiExceptionWhenTheRefreshedTokenIsAlsoRejected(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')
            ->once()
            ->with(Mockery::any(), ['Authorization' => 'Bearer stale-jwt'])
            ->andReturn(['status' => 401, 'body' => '{"error":"invalid_token"}']);
        $httpClient->shouldReceive('get')
            ->once()
            ->with(Mockery::any(), ['Authorization' => 'Bearer fresh-jwt'])
            ->andReturn(['status' => 401, 'body' => '{"error":"invalid_token"}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManagerExpectingRefresh(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API payment request failed with HTTP status 401.');
        $this->expectExceptionCode(401);
        $service->getPayment('pay_123');
    }

    public function testGetPaymentDoesNotRetryOnANonAuthStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        // ->once() plus makeTokenManager()'s shouldNotReceive('delete'/'post') proves a 403 is
        // treated as terminal rather than dragged through a pointless token refresh.
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 403, 'body' => '{"error":"forbidden"}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API payment request failed with HTTP status 403.');
        $this->expectExceptionCode(403);
        $service->getPayment('pay_123');
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
