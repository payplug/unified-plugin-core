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
use PayplugUnifiedCore\Dto\BrowserDto;
use PayplugUnifiedCore\Dto\CustomerDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Output\HostedPaymentOutput;
use PayplugUnifiedCore\Services\UnifiedApiHostedPaymentService;
use PayplugUnifiedCore\Tests\Support\HostedFieldDtoBuilder;

final class UnifiedApiHostedPaymentServiceTest extends MockeryTestCase
{
    /**
     * Body-shape coverage (which optional fields end up in the request, capture's default, the
     * JSON-encoding edge cases) lives in HostedFieldDtoTest now — the service no longer builds the
     * body itself, it just forwards $dto->createPayloadBody(). This test proves that delegation:
     * the mock asserts the exact bytes sent match what the DTO itself produces, not a duplicated
     * literal array.
     */
    public function testCreateHostedPaymentSendsTheDtosPayloadBodyAndReturnsADirectSuccessResult(): void
    {
        $body = json_encode(['id' => 'pay_123']);
        $dto = HostedFieldDtoBuilder::valid()
            ->withDescription('Order #456')
            ->withDescriptor('MY SHOP Order #456')
            ->withNotificationUrl('https://shop.example.com/payplug/notification')
            ->withExtraData('internal_ref_789')
            ->withBrowser(new BrowserDto('10.1.1.1', 'https://shop.example.com/cart', 'Mozilla/5.0'))
            ->withCustomer(new CustomerDto('john.snow', 'john.snow@example.com'))
            ->withPaymentMethod(['details' => ['fullName' => 'John Snow', 'selectedBrand' => 'visa']])
            ->build();

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/payments',
                $dto->createPayloadBody(),
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createHostedPayment($dto);

        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (kept as a regression guard, not removed)
        self::assertInstanceOf(HostedPaymentOutput::class, $result);
        self::assertSame(200, $result->status);
        self::assertSame($body, $result->body);
        self::assertNull($result->redirectUrl);
    }

    public function testCreateHostedPaymentExtractsTheRedirectUrlWhenThreeDsIsPending(): void
    {
        $body = json_encode(['id' => 'pay_123', 'redirect' => ['url' => 'https://3ds.example.com/challenge']]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 201, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertSame('https://3ds.example.com/challenge', $result->redirectUrl);
    }

    public function testCreateHostedPaymentReturnsNullRedirectUrlWhenTheBodyIsNotValidJson(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => 'not json']);

        $service = $this->makeService($httpClient);

        $result = $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertNull($result->redirectUrl);
    }

    public function testCreateHostedPaymentReturnsNullRedirectUrlWhenTheRedirectUrlIsNotAString(): void
    {
        $body = json_encode(['redirect' => ['url' => 12345]]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build());

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

        $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build());
    }

    public function testCreateHostedPaymentThrowsApiExceptionOnNonSuccessStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 500, 'body' => '{"error":"boom"}']);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API hosted payment request failed with HTTP status 500.');
        $this->expectExceptionCode(500);
        $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build());
    }

    /**
     * @dataProvider successStatusProvider
     */
    public function testCreateHostedPaymentTreatsTheWhole2xxRangeAsSuccess(int $status): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => $status, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        self::assertSame($status, $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build())->status);
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
        $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build());
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
        $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build());
    }

    public function testCreateHostedPaymentThrowsApiExceptionWhenTheResponseIsMissingItsStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['body' => '{}']); // missing 'status'

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API HTTP client response is malformed.');
        $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build());
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

        $result = $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build());

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
        $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build());
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
        $service->createHostedPayment(HostedFieldDtoBuilder::valid()->build());
    }

    public function testCreateHostedPaymentThrowsInvalidHostedFieldExceptionBeforeAnyNetworkCall(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(InvalidHostedFieldException::class);
        $this->expectExceptionMessage('hfToken must not be empty.');
        $service->createHostedPayment(HostedFieldDtoBuilder::valid()->withHfToken('')->build());
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
            'secret_xyz'
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

    /**
     * Validation is expected to throw before the service ever resolves a token, so this
     * TokenManager must see zero interaction with either the cache or the identity provider.
     */
    private function makeTokenManagerExpectingNoInteraction(): TokenManager
    {
        $tokenCache = Mockery::mock(ITokenCache::class);
        $tokenCache->shouldNotReceive('get');
        $tokenCache->shouldNotReceive('delete');

        $oauthHttpClient = Mockery::mock(IOAuthHttpClient::class);
        $oauthHttpClient->shouldNotReceive('post');

        $oauth2Client = new OAuth2Client($oauthHttpClient, 'https://idp.example.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        return new TokenManager($tokenCache, $oauth2Client);
    }
}
