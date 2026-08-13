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
use PayplugUnifiedCore\Contracts\PaymentRequestPayload;
use PayplugUnifiedCore\Dto\BrowserDto;
use PayplugUnifiedCore\Dto\CustomerDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Exceptions\InvalidPaymentException;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Output\PaymentOutput;
use PayplugUnifiedCore\Services\UnifiedApiPaymentService;
use PayplugUnifiedCore\Tests\Support\HostedFieldDtoBuilder;
use PayplugUnifiedCore\Tests\Support\PaymentDtoBuilder;

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

    public function testGetOperationReturnsStatusAndBodyOnSuccess(): void
    {
        $body = json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '000000072', 'amount' => 7400]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')
            ->once()
            ->with('https://api.payplug.com/processing-operations/operations/public/op_123', ['Authorization' => 'Bearer cached-jwt'])
            ->andReturn(['status' => 200, 'body' => $body]);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => 200, 'body' => $body], $service->getOperation('op_123'));
    }

    public function testGetOperationUrlEncodesTheOperationId(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')
            ->once()
            ->with('https://api.payplug.com/processing-operations/operations/public/op%2F123%20456', ['Authorization' => 'Bearer cached-jwt'])
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => 200, 'body' => '{}'], $service->getOperation('op/123 456'));
    }

    /**
     * Unlike getPayment(), a 404 here is just another failure — no dedicated exception type, since
     * no caller currently needs to distinguish "unknown operation id" from any other API error.
     */
    public function testGetOperationThrowsApiExceptionOnA404(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 404, 'body' => '{"error":"not_found"}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API operation request failed with HTTP status 404.');
        $this->expectExceptionCode(404);
        $service->getOperation('op_123');
    }

    public function testGetOperationThrowsApiExceptionOnNonSuccessStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 500, 'body' => '{"error":"boom"}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API operation request failed with HTTP status 500.');
        $this->expectExceptionCode(500);
        $service->getOperation('op_123');
    }

    public function testGetOperationRetriesOnceWithAFreshTokenWhenTheCachedOneIsRejected(): void
    {
        $body = json_encode(['id' => 'op_123', 'execCode' => '0000', 'orderId' => '000000072', 'amount' => 7400]);
        $url = 'https://api.payplug.com/processing-operations/operations/public/op_123';

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

        self::assertSame(['status' => 200, 'body' => $body], $service->getOperation('op_123'));
    }

    /**
     * Body-shape coverage (which optional fields end up in the request, capture's default, the
     * JSON-encoding edge cases) lives in HostedFieldDtoTest now — the service no longer builds the
     * body itself, it just forwards $dto->createPayloadBody(). This test proves that delegation:
     * the mock asserts the exact bytes sent match what the DTO itself produces, not a duplicated
     * literal array.
     */
    public function testCreatePaymentSendsTheDtosPayloadBodyAndReturnsADirectSuccessResult(): void
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

        $result = $service->createPayment($dto);

        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (kept as a regression guard, not removed)
        self::assertInstanceOf(PaymentOutput::class, $result);
        self::assertSame(200, $result->status);
        self::assertSame($body, $result->body);
        self::assertNull($result->redirectUrl);
    }

    public function testCreatePaymentExtractsTheRedirectUrlWhenThreeDsIsPending(): void
    {
        $body = json_encode(['id' => 'pay_123', 'redirect' => ['url' => 'https://3ds.example.com/challenge']]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 201, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertSame('https://3ds.example.com/challenge', $result->redirectUrl);
    }

    public function testCreatePaymentReturnsNullRedirectUrlWhenTheBodyIsNotValidJson(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => 'not json']);

        $service = $this->makeService($httpClient);

        $result = $service->createPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertNull($result->redirectUrl);
    }

    public function testCreatePaymentReturnsNullRedirectUrlWhenTheRedirectUrlIsNotAString(): void
    {
        $body = json_encode(['redirect' => ['url' => 12345]]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertNull($result->redirectUrl);
    }

    public function testCreatePaymentExtractsAndDecodesTheRedirectHtmlWhenThreeDsIsPending(): void
    {
        $html = '<html><body>3DS challenge form</body></html>';
        $body = json_encode(['id' => 'pay_123', 'execCode' => '0001', 'redirect' => ['html' => base64_encode($html)]]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertSame($html, $result->redirectHtml);
        self::assertNull($result->redirectUrl);
    }

    public function testCreatePaymentReturnsNullRedirectHtmlWhenTheHtmlIsNotValidBase64(): void
    {
        $body = json_encode(['redirect' => ['html' => 'not valid base64!!!']]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertNull($result->redirectHtml);
    }

    public function testCreatePaymentReturnsNullRedirectHtmlWhenTheRedirectHtmlIsNotAString(): void
    {
        $body = json_encode(['redirect' => ['html' => 12345]]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertNull($result->redirectHtml);
    }

    public function testCreatePaymentReturnsNullRedirectHtmlWhenTheRedirectHtmlIsAnEmptyString(): void
    {
        $body = json_encode(['redirect' => ['html' => '']]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertNull($result->redirectHtml);
    }

    public function testCreatePaymentExtractsTheAliasIdWhenTheResponseContainsOne(): void
    {
        $body = json_encode(['id' => 'pay_123', 'paymentMethod' => ['id' => 'alias_789']]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->createPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertSame('alias_789', $result->aliasId);
    }

    public function testCreatePaymentReturnsNullAliasIdWhenTheResponseHasNone(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => json_encode(['id' => 'pay_123'])]);

        $service = $this->makeService($httpClient);

        $result = $service->createPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertNull($result->aliasId);
    }

    public function testCreatePaymentSendsAPaymentDtosPayloadBodyWithNoHfToken(): void
    {
        $dto = PaymentDtoBuilder::valid()->build();

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/payments',
                $dto->createPayloadBody(),
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => json_encode(['id' => 'pay_123', 'paymentMethod' => ['id' => 'alias_789']])]);

        $service = $this->makeService($httpClient);

        $result = $service->createPayment($dto);

        self::assertArrayNotHasKey('hfToken', $dto->createPayloadBody());
        self::assertSame('alias_789', $result->aliasId);
    }

    public function testCreatePaymentThrowsInvalidPaymentExceptionBeforeAnyNetworkCallForAnInvalidPaymentDto(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('aliasId must not be empty.');
        $service->createPayment(PaymentDtoBuilder::valid()->withAliasId('')->build());
    }

    public function testCreatePaymentThrowsLogicExceptionForAnUnsupportedPaymentRequestPayloadImplementation(): void
    {
        $dto = new class () implements PaymentRequestPayload {
            public function createPayloadBody(): array
            {
                return [];
            }
        };

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(\LogicException::class);
        $service->createPayment($dto);
    }

    public function testCreatePaymentNormalizesATrailingSlashOnTheBaseUrl(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with('https://api.payplug.com/payments', Mockery::any(), Mockery::any())
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient, 'https://api.payplug.com/');

        $service->createPayment(HostedFieldDtoBuilder::valid()->build());
    }

    public function testCreatePaymentThrowsApiExceptionOnNonSuccessStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 500, 'body' => '{"error":"boom"}']);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API payment creation request failed with HTTP status 500.');
        $this->expectExceptionCode(500);
        $service->createPayment(HostedFieldDtoBuilder::valid()->build());
    }

    /**
     * @dataProvider successStatusProvider
     */
    public function testCreatePaymentTreatsTheWhole2xxRangeAsSuccess(int $status): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => $status, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        self::assertSame($status, $service->createPayment(HostedFieldDtoBuilder::valid()->build())->status);
    }

    /**
     * @dataProvider failureStatusProvider
     */
    public function testCreatePaymentTreatsStatusesJustOutsideThe2xxRangeAsFailures(int $status): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => $status, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode($status);
        $service->createPayment(HostedFieldDtoBuilder::valid()->build());
    }

    public function testCreatePaymentThrowsApiExceptionWhenTheResponseIsMissingItsBody(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200]); // missing 'body'

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API HTTP client response is malformed.');
        $service->createPayment(HostedFieldDtoBuilder::valid()->build());
    }

    public function testCreatePaymentThrowsApiExceptionWhenTheResponseIsMissingItsStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['body' => '{}']); // missing 'status'

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API HTTP client response is malformed.');
        $service->createPayment(HostedFieldDtoBuilder::valid()->build());
    }

    public function testCreatePaymentRetriesOnceWithAFreshTokenWhenTheCachedOneIsRejected(): void
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

        $result = $service->createPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertSame(200, $result->status);
    }

    public function testCreatePaymentThrowsApiExceptionWhenTheRefreshedTokenIsAlsoRejected(): void
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
        $this->expectExceptionMessage('Unified API payment creation request failed with HTTP status 401.');
        $this->expectExceptionCode(401);
        $service->createPayment(HostedFieldDtoBuilder::valid()->build());
    }

    public function testCreatePaymentDoesNotRetryOnANonAuthStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        // ->once() plus makeTokenManager()'s shouldNotReceive('delete') proves a 403 is treated as
        // terminal rather than dragged through a pointless token refresh.
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 403, 'body' => '{"error":"forbidden"}']);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API payment creation request failed with HTTP status 403.');
        $this->expectExceptionCode(403);
        $service->createPayment(HostedFieldDtoBuilder::valid()->build());
    }

    public function testCreatePaymentThrowsInvalidHostedFieldExceptionBeforeAnyNetworkCall(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(InvalidHostedFieldException::class);
        $this->expectExceptionMessage('hfToken must not be empty.');
        $service->createPayment(HostedFieldDtoBuilder::valid()->withHfToken('')->build());
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

    private function makeService(
        IUnifiedApiHttpClient $httpClient,
        string $baseUrl = 'https://api.payplug.com',
        ?TokenManager $tokenManager = null
    ): UnifiedApiPaymentService {
        return new UnifiedApiPaymentService(
            $httpClient,
            $tokenManager ?? $this->makeTokenManager(),
            $baseUrl,
            'client_abc',
            'secret_xyz'
        );
    }
}
