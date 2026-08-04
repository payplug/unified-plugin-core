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
use PayplugUnifiedCore\Models\HostedPaymentResult;
use PayplugUnifiedCore\Services\UnifiedApiHostedPaymentService;

final class UnifiedApiHostedPaymentServiceTest extends MockeryTestCase
{
    public function testCreateHostedPaymentSendsTheExpectedRequestAndReturnsADirectSuccessResult(): void
    {
        $body = json_encode(['id' => 'pay_123']);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/payments',
                Mockery::on(static function (array $requestBody): bool {
                    return $requestBody === [
                        'account' => ['id' => 'acc_123'],
                        'amount' => 1000,
                        'currency' => 'EUR',
                        'orderId' => 'order_456',
                        'capture' => true,
                        'hfToken' => 'hf_abc',
                    ];
                }),
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');

        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (kept as a regression guard, not removed)
        self::assertInstanceOf(HostedPaymentResult::class, $result);
        self::assertSame(200, $result->status);
        self::assertSame($body, $result->body);
        self::assertNull($result->redirectUrl);
    }

    public function testCreateHostedPaymentIncludesBrowserCustomerDescriptionAndPaymentMethodWhenProvided(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/payments',
                Mockery::on(static function (array $requestBody): bool {
                    return $requestBody === [
                        'account' => ['id' => 'acc_123'],
                        'amount' => 1000,
                        'currency' => 'EUR',
                        'orderId' => 'order_456',
                        'capture' => true,
                        'hfToken' => 'hf_abc',
                        'paymentMethod' => ['details' => ['fullName' => 'John Snow', 'selectedBrand' => 'visa']],
                        'browser' => ['ip' => '10.1.1.1', 'referrer' => 'https://shop.example.com/cart', 'userAgent' => 'Mozilla/5.0'],
                        'customer' => ['id' => 'john.snow', 'email' => 'john.snow@example.com'],
                        'description' => 'Order #456',
                        'descriptor' => 'MY SHOP Order #456',
                        'notificationUrl' => 'https://shop.example.com/payplug/notification',
                        'extraData' => 'internal_ref_789',
                    ];
                }),
                Mockery::any()
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $service->createHostedPayment(
            'hf_abc',
            1000,
            'EUR',
            'order_456',
            ['ip' => '10.1.1.1', 'referrer' => 'https://shop.example.com/cart', 'userAgent' => 'Mozilla/5.0'],
            ['id' => 'john.snow', 'email' => 'john.snow@example.com'],
            'Order #456',
            ['details' => ['fullName' => 'John Snow', 'selectedBrand' => 'visa']],
            'MY SHOP Order #456',
            'https://shop.example.com/payplug/notification',
            'internal_ref_789'
        );
    }

    public function testCreateHostedPaymentOmitsAllOptionalFieldsWhenNotProvided(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(static function (array $requestBody): bool {
                    return !isset($requestBody['paymentMethod'])
                        && !isset($requestBody['browser'])
                        && !isset($requestBody['customer'])
                        && !isset($requestBody['description'])
                        && !isset($requestBody['descriptor'])
                        && !isset($requestBody['notificationUrl'])
                        && !isset($requestBody['extraData']);
                }),
                Mockery::any()
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');
    }

    /**
     * Unlike the other tests, asserts on real json_encode() output, not PHP array equality — that's
     * what catches a PHP array being structurally right but serializing to the wrong JSON shape.
     */
    public function testCreateHostedPaymentsJsonEncodedBodyOmitsPaymentMethodEntirelyWhenDetailsAreNotProvided(): void
    {
        $capturedBody = null;

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (array $body) use (&$capturedBody): bool {
                $capturedBody = $body;

                return true;
            }), Mockery::any())
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');

        self::assertStringNotContainsString('paymentMethod', (string) json_encode($capturedBody));
    }

    public function testCreateHostedPaymentsJsonEncodedBodySerializesPaymentMethodAsAnObject(): void
    {
        $capturedBody = null;

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(Mockery::any(), Mockery::on(static function (array $body) use (&$capturedBody): bool {
                $capturedBody = $body;

                return true;
            }), Mockery::any())
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456', null, null, null, ['details' => ['fullName' => 'John Snow']]);

        self::assertStringContainsString('"paymentMethod":{"details":{"fullName":"John Snow"}}', (string) json_encode($capturedBody));
    }

    public function testCreateHostedPaymentExtractsTheRedirectUrlWhenThreeDsIsPending(): void
    {
        $body = json_encode(['id' => 'pay_123', 'redirect' => ['url' => 'https://3ds.example.com/challenge']]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 201, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');

        self::assertSame('https://3ds.example.com/challenge', $result->redirectUrl);
    }

    public function testCreateHostedPaymentReturnsNullRedirectUrlWhenTheBodyIsNotValidJson(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => 'not json']);

        $service = $this->makeService($httpClient);

        $result = $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');

        self::assertNull($result->redirectUrl);
    }

    public function testCreateHostedPaymentReturnsNullRedirectUrlWhenTheRedirectUrlIsNotAString(): void
    {
        $body = json_encode(['redirect' => ['url' => 12345]]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');

        self::assertNull($result->redirectUrl);
    }

    public function testCreateHostedPaymentNormalizesATrailingSlashOnTheBaseUrl(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with('https://api.payplug.com/payments', Mockery::any(), Mockery::any())
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient, 'https://api.payplug.com/');

        $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');
    }

    public function testCreateHostedPaymentThrowsApiExceptionOnNonSuccessStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 500, 'body' => '{"error":"boom"}']);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API hosted payment request failed with HTTP status 500.');
        $this->expectExceptionCode(500);
        $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');
    }

    /**
     * @dataProvider successStatusProvider
     */
    public function testCreateHostedPaymentTreatsTheWhole2xxRangeAsSuccess(int $status): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => $status, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        self::assertSame($status, $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456')->status);
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
    public function testCreateHostedPaymentTreatsStatusesJustOutsideThe2xxRangeAsFailures(int $status): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => $status, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode($status);
        $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');
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

    public function testCreateHostedPaymentThrowsApiExceptionWhenTheResponseIsMissingItsBody(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200]); // missing 'body'

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API HTTP client response is malformed.');
        $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');
    }

    public function testCreateHostedPaymentThrowsApiExceptionWhenTheResponseIsMissingItsStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['body' => '{}']); // missing 'status'

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API HTTP client response is malformed.');
        $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');
    }

    public function testCreateHostedPaymentRetriesOnceWithAFreshTokenWhenTheCachedOneIsRejected(): void
    {
        $body = json_encode(['id' => 'pay_123']);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(Mockery::any(), Mockery::any(), ['Authorization' => 'Bearer stale-jwt', 'Content-Type' => 'application/json'])
            ->andReturn(['status' => 401, 'body' => '{"error":"invalid_token"}']);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(Mockery::any(), Mockery::any(), ['Authorization' => 'Bearer fresh-jwt', 'Content-Type' => 'application/json'])
            ->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingRefresh());

        $result = $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');

        self::assertSame(200, $result->status);
    }

    public function testCreateHostedPaymentThrowsApiExceptionWhenTheRefreshedTokenIsAlsoRejected(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(Mockery::any(), Mockery::any(), ['Authorization' => 'Bearer stale-jwt', 'Content-Type' => 'application/json'])
            ->andReturn(['status' => 401, 'body' => '{"error":"invalid_token"}']);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(Mockery::any(), Mockery::any(), ['Authorization' => 'Bearer fresh-jwt', 'Content-Type' => 'application/json'])
            ->andReturn(['status' => 401, 'body' => '{"error":"invalid_token"}']);

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingRefresh());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API hosted payment request failed with HTTP status 401.');
        $this->expectExceptionCode(401);
        $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');
    }

    public function testCreateHostedPaymentDoesNotRetryOnANonAuthStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        // ->once() plus makeTokenManager()'s shouldNotReceive('delete') proves a 403 is treated as
        // terminal rather than dragged through a pointless token refresh.
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 403, 'body' => '{"error":"forbidden"}']);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API hosted payment request failed with HTTP status 403.');
        $this->expectExceptionCode(403);
        $service->createHostedPayment('hf_abc', 1000, 'EUR', 'order_456');
    }

    private function makeService(
        IUnifiedApiHttpClient $httpClient,
        string $baseUrl = 'https://api.payplug.com',
        ?TokenManager $tokenManager = null
    ): UnifiedApiHostedPaymentService {
        return new UnifiedApiHostedPaymentService(
            $httpClient,
            $tokenManager ?? $this->makeTokenManager(),
            $baseUrl,
            'client_abc',
            'secret_xyz',
            'acc_123'
        );
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
