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
use PayplugUnifiedCore\Exceptions\AmountExceedsAvailableException;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\AuthorizationExpiredException;
use PayplugUnifiedCore\Exceptions\CancellationAmountException;
use PayplugUnifiedCore\Exceptions\CaptureAmountException;
use PayplugUnifiedCore\Exceptions\CardOperationException;
use PayplugUnifiedCore\Exceptions\InvalidCancellationRequestException;
use PayplugUnifiedCore\Exceptions\InvalidCaptureRequestException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Exceptions\InvalidPaymentException;
use PayplugUnifiedCore\Exceptions\InvalidRefundRequestException;
use PayplugUnifiedCore\Exceptions\MultipleCaptureNotAllowedException;
use PayplugUnifiedCore\Exceptions\OperationConflictException;
use PayplugUnifiedCore\Exceptions\PartialCancellationNotAllowedException;
use PayplugUnifiedCore\Exceptions\PaymentAlreadyCancelledException;
use PayplugUnifiedCore\Exceptions\PaymentAlreadyCapturedException;
use PayplugUnifiedCore\Exceptions\PaymentNotCapturableException;
use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Exceptions\PaymentNotVoidableException;
use PayplugUnifiedCore\Exceptions\RefundAmountException;
use PayplugUnifiedCore\Output\CancellationOutput;
use PayplugUnifiedCore\Output\CaptureOutput;
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
            ->with('https://api.payplug.com/api/payment-gateway/payments/pay_123', ['Authorization' => 'Bearer cached-jwt'])
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
            ->with('https://api.payplug.com/api/payment-gateway/payments/pay%2F123%20456', ['Authorization' => 'Bearer cached-jwt'])
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
            ->with('https://api.payplug.com/api/payment-gateway/payments/pay_123', ['Authorization' => 'Bearer cached-jwt'])
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
        $url = 'https://api.payplug.com/api/payment-gateway/payments/pay_123';

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

    public function testGetOperationNormalizesATrailingSlashOnTheBaseUrl(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        // The exact-URL expectation is the point: a missing rtrim() would produce a double slash.
        $httpClient->shouldReceive('get')
            ->once()
            ->with('https://api.payplug.com/processing-operations/operations/public/op_123', ['Authorization' => 'Bearer cached-jwt'])
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com/', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => 200, 'body' => '{}'], $service->getOperation('op_123'));
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

    /**
     * @dataProvider successStatusProvider
     */
    public function testGetOperationTreatsTheWhole2xxRangeAsSuccess(int $status): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => $status, 'body' => '{}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        self::assertSame(['status' => $status, 'body' => '{}'], $service->getOperation('op_123'));
    }

    /**
     * @dataProvider failureStatusProvider
     */
    public function testGetOperationTreatsStatusesJustOutsideThe2xxRangeAsFailures(int $status): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => $status, 'body' => '{}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionCode($status);
        $service->getOperation('op_123');
    }

    public function testGetOperationThrowsApiExceptionWhenTheResponseIsMissingItsBody(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 200]); // missing 'body'

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API HTTP client response is malformed.');
        $service->getOperation('op_123');
    }

    public function testGetOperationThrowsApiExceptionWhenTheResponseIsMissingItsStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('get')->once()->andReturn(['body' => '{}']); // missing 'status'

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API HTTP client response is malformed.');
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

    public function testGetOperationThrowsApiExceptionWhenTheRefreshedTokenIsAlsoRejected(): void
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
        $this->expectExceptionMessage('Unified API operation request failed with HTTP status 401.');
        $this->expectExceptionCode(401);
        $service->getOperation('op_123');
    }

    public function testGetOperationDoesNotRetryOnANonAuthStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        // ->once() plus makeTokenManager()'s shouldNotReceive('delete'/'post') proves a 403 is
        // treated as terminal rather than dragged through a pointless token refresh.
        $httpClient->shouldReceive('get')->once()->andReturn(['status' => 403, 'body' => '{"error":"forbidden"}']);

        $service = new UnifiedApiPaymentService($httpClient, $this->makeTokenManager(), 'https://api.payplug.com', 'client_abc', 'secret_xyz');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API operation request failed with HTTP status 403.');
        $this->expectExceptionCode(403);
        $service->getOperation('op_123');
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
                'https://api.payplug.com/api/payment-gateway/payments',
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

    public function testCreatePaymentExtractsMaxCaptureDateAndRemainingCapturableAmountForAnAuthorizationOnlyPayment(): void
    {
        $body = json_encode(['id' => 'pay_123', 'requestedAmount' => 1000, 'maxCaptureDate' => '2026-09-25T12:00:00Z']);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $dto = HostedFieldDtoBuilder::valid()->build();
        $dto->common->capture = false;

        $result = $service->createPayment($dto);

        self::assertSame('2026-09-25T12:00:00Z', $result->maxCaptureDate);
        self::assertSame(1000, $result->remainingCapturableAmount);
    }

    /**
     * A partial-authorization response (the issuer approved less than requested) carries a lower
     * "amount" than "requestedAmount" — remainingCapturableAmount must reflect what was actually
     * authorized ("amount"), not what was originally asked for ("requestedAmount").
     */
    public function testCreatePaymentPrioritizesAmountOverRequestedAmountForRemainingCapturableAmount(): void
    {
        $body = json_encode(['id' => 'pay_123', 'amount' => 700, 'requestedAmount' => 1000]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $dto = HostedFieldDtoBuilder::valid()->build();
        $dto->common->capture = false;

        $result = $service->createPayment($dto);

        self::assertSame(700, $result->remainingCapturableAmount);
    }

    public function testCreatePaymentLeavesRemainingCapturableAmountNullForADirectPayment(): void
    {
        $body = json_encode(['id' => 'pay_123', 'requestedAmount' => 1000, 'amount' => 1000]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        // HostedFieldDtoBuilder::valid() defaults to capture=true (a direct payment).
        $result = $service->createPayment(HostedFieldDtoBuilder::valid()->build());

        self::assertNull($result->remainingCapturableAmount);
    }

    public function testCreatePaymentSendsAPaymentDtosPayloadBodyWithNoHfToken(): void
    {
        $dto = PaymentDtoBuilder::valid()->build();

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments',
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
            ->with('https://api.payplug.com/api/payment-gateway/payments', Mockery::any(), Mockery::any())
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

    public function testCreateRefundSendsAccountIdAndOrderIdAndReturnsStatusAndBodyOnAFullRefund(): void
    {
        $body = json_encode(['id' => 'pay_123', 'execCode' => '0000']);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                    'submerchantExternalId' => 'sub_1',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        self::assertSame(['status' => 200, 'body' => $body], $service->createRefund('pay_123', 'acc_123', 'order_1', 'Refund for order order_1', 'sub_1'));
    }

    public function testCreateRefundIncludesAmountInTheBodyForAPartialRefund(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                    'submerchantExternalId' => 'sub_1',
                    'amount' => 500,
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        self::assertSame(['status' => 200, 'body' => '{}'], $service->createRefund('pay_123', 'acc_123', 'order_1', 'Refund for order order_1', 'sub_1', 500));
    }

    public function testCreateRefundUrlEncodesTheOperationId(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay%2F123%20456/refund',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                    'submerchantExternalId' => 'sub_1',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        self::assertSame(['status' => 200, 'body' => '{}'], $service->createRefund('pay/123 456', 'acc_123', 'order_1', 'Refund for order order_1', 'sub_1'));
    }

    public function testCreateRefundThrowsInvalidRefundRequestExceptionForAnEmptyOrderIdBeforeAnyNetworkCall(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(InvalidRefundRequestException::class);
        $this->expectExceptionMessage('orderId must not be empty.');
        $service->createRefund('pay_123', 'acc_123', '', 'Refund for order order_1', 'sub_1');
    }

    public function testCreateRefundThrowsInvalidRefundRequestExceptionForAnEmptyDescriptionBeforeAnyNetworkCall(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(InvalidRefundRequestException::class);
        $this->expectExceptionMessage('description must not be empty.');
        $service->createRefund('pay_123', 'acc_123', 'order_1', '', 'sub_1');
    }

    /**
     * A payment made under a MID configuration that owns no submerchant (every non-EUR one today)
     * must be refunded without one too — sending the key against such a payment is rejected with
     * 400 ("Invalid parameter."), so it is omitted from the body entirely rather than sent as null.
     */
    public function testCreateRefundOmitsSubmerchantExternalIdFromTheBodyWhenNoneIsGiven(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        self::assertSame(['status' => 200, 'body' => '{}'], $service->createRefund('pay_123', 'acc_123', 'order_1', 'Refund for order order_1'));
    }

    /**
     * A CMS reading an unset submerchant out of its own settings storage hands back an empty
     * string far more often than a real null — and until PRE-3589's Assert::notEmpty() was
     * dropped, that empty string was a loud local error rather than something sent on the wire.
     * It means the same thing as null (this configuration owns no submerchant), so it is omitted
     * the same way rather than sent as "", which a non-EUR configuration rejects with 400
     * ("Invalid parameter.").
     */
    public function testCreateRefundOmitsSubmerchantExternalIdFromTheBodyWhenItIsAnEmptyString(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        self::assertSame(['status' => 200, 'body' => '{}'], $service->createRefund('pay_123', 'acc_123', 'order_1', 'Refund for order order_1', ''));
    }

    /**
     * Without this key the platform has to infer what $amount's minor units mean — unambiguous
     * only while every payment is in the account's own default currency.
     */
    public function testCreateRefundIncludesCurrencyInTheBodyWhenGiven(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                    'amount' => 6800,
                    'currency' => 'USD',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        self::assertSame(['status' => 200, 'body' => '{}'], $service->createRefund('pay_123', 'acc_123', 'order_1', 'Refund for order order_1', null, 6800, 'USD'));
    }

    /**
     * An empty currency is a caller that failed to resolve one, never a meaningful value. It is
     * mapped onto the same "omit the key" behavior as null — which is itself a supported mode
     * (the platform then interprets $amount's minor units against the account default) — rather
     * than sent as "" for the API to reject.
     */
    public function testCreateRefundOmitsCurrencyFromTheBodyWhenItIsAnEmptyString(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Refund for order order_1',
                    'amount' => 6800,
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        self::assertSame(['status' => 200, 'body' => '{}'], $service->createRefund('pay_123', 'acc_123', 'order_1', 'Refund for order order_1', null, 6800, ''));
    }

    /**
     * @dataProvider nonPositiveAmountProvider
     */
    public function testCreateRefundThrowsRefundAmountExceptionForANonPositiveAmountBeforeAnyNetworkCall(int $amount): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(RefundAmountException::class);
        $this->expectExceptionMessage('amount must be greater than zero.');
        $service->createRefund('pay_123', 'acc_123', 'order_1', 'Refund for order order_1', 'sub_1', $amount);
    }

    /**
     * @return array<string, array{int}>
     */
    public function nonPositiveAmountProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-500],
        ];
    }

    public function testCreateRefundThrowsPaymentNotFoundExceptionOnA404(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 404, 'body' => '{"error":"not_found"}']);

        $service = $this->makeService($httpClient);

        $this->expectException(PaymentNotFoundException::class);
        $this->expectExceptionMessage('Unified API has no payment "pay_123".');
        $this->expectExceptionCode(404);
        $service->createRefund('pay_123', 'acc_123', 'order_1', 'Refund for order order_1', 'sub_1');
    }

    public function testCreateRefundThrowsApiExceptionOnNonSuccessStatus(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 500, 'body' => '{"error":"boom"}']);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API refund request failed with HTTP status 500.');
        $this->expectExceptionCode(500);
        $service->createRefund('pay_123', 'acc_123', 'order_1', 'Refund for order order_1', 'sub_1');
    }

    public function testCreateRefundRetriesOnceWithAFreshTokenWhenTheCachedOneIsRejected(): void
    {
        $url = 'https://api.payplug.com/api/payment-gateway/payments/pay_123/refund';

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with($url, Mockery::any(), ['Authorization' => 'Bearer stale-jwt', 'Content-Type' => 'application/json'])
            ->andReturn(['status' => 401, 'body' => '{"error":"invalid_token"}']);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with($url, Mockery::any(), ['Authorization' => 'Bearer fresh-jwt', 'Content-Type' => 'application/json'])
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingRefresh());

        self::assertSame(['status' => 200, 'body' => '{}'], $service->createRefund('pay_123', 'acc_123', 'order_1', 'Refund for order order_1', 'sub_1'));
    }

    public function testCapturePaymentSendsAccountIdAndOrderIdAndReturnsOutputOnAFullCapture(): void
    {
        $body = json_encode(['id' => 'pay_123', 'amount' => 1000, 'requestedAmount' => 1000]);

        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/capture',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Capture for order order_1',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => $body]);

        $service = $this->makeService($httpClient);

        $result = $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1');

        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (kept as a regression guard, not removed)
        self::assertInstanceOf(CaptureOutput::class, $result);
        self::assertSame(200, $result->status);
        self::assertSame($body, $result->body);
        self::assertSame(1000, $result->capturedAmount);
        self::assertSame(1000, $result->requestedAmount);
        self::assertSame(0, $result->remainingCapturableAmount);
    }

    public function testCapturePaymentIncludesAmountInTheBodyForAPartialCapture(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/capture',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Capture for order order_1',
                    'amount' => 300,
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => json_encode(['amount' => 300, 'requestedAmount' => 1000])]);

        $service = $this->makeService($httpClient);

        $result = $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1', 300);

        self::assertSame(300, $result->capturedAmount);
        self::assertSame(700, $result->remainingCapturableAmount);
    }

    public function testCapturePaymentIncludesCurrencyInTheBodyWhenGiven(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/capture',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Capture for order order_1',
                    'amount' => 300,
                    'currency' => 'USD',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1', 300, null, 'USD');
    }

    public function testCapturePaymentOmitsCurrencyFromTheBodyWhenItIsAnEmptyString(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/capture',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Capture for order order_1',
                    'amount' => 300,
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1', 300, null, '');
    }

    public function testCapturePaymentIncludesExtraDataWhenGiven(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/capture',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Capture for order order_1',
                    'extraData' => 'internal_ref_789',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1', null, 'internal_ref_789');
    }

    public function testCapturePaymentUrlEncodesThePaymentId(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay%2F123%20456/capture',
                Mockery::any(),
                Mockery::any()
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $service->capturePayment('pay/123 456', 'acc_123', 'order_1', 'Capture for order order_1');
    }

    public function testCapturePaymentExtractsMaxCaptureDate(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => json_encode(['maxCaptureDate' => '2026-09-25T12:00:00Z'])]);

        $service = $this->makeService($httpClient);

        $result = $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1');

        self::assertSame('2026-09-25T12:00:00Z', $result->maxCaptureDate);
    }

    public function testCapturePaymentThrowsInvalidCaptureRequestExceptionForAnEmptyOrderIdBeforeAnyNetworkCall(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(InvalidCaptureRequestException::class);
        $this->expectExceptionMessage('orderId must not be empty.');
        $service->capturePayment('pay_123', 'acc_123', '', 'Capture for order order_1');
    }

    public function testCapturePaymentThrowsInvalidCaptureRequestExceptionForAnEmptyDescriptionBeforeAnyNetworkCall(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(InvalidCaptureRequestException::class);
        $this->expectExceptionMessage('description must not be empty.');
        $service->capturePayment('pay_123', 'acc_123', 'order_1', '');
    }

    /**
     * @dataProvider nonPositiveAmountProvider
     */
    public function testCapturePaymentThrowsCaptureAmountExceptionForANonPositiveAmountBeforeAnyNetworkCall(int $amount): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(CaptureAmountException::class);
        $this->expectExceptionMessage('amount must be greater than zero.');
        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1', $amount);
    }

    public function testCapturePaymentThrowsPaymentNotFoundExceptionOnA404(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 404, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $this->expectException(PaymentNotFoundException::class);
        $this->expectExceptionMessage('Unified API has no payment "pay_123".');
        $this->expectExceptionCode(404);
        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1');
    }

    public function testCapturePaymentThrowsOperationConflictExceptionOnA409(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 409, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $this->expectException(OperationConflictException::class);
        $this->expectExceptionCode(409);
        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1');
    }

    public function testCapturePaymentThrowsCardOperationExceptionForAnIssuerRefusalExecCode(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 400, 'body' => json_encode(['execCode' => '4001', 'message' => 'Card declined by issuer.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(CardOperationException::class);
        $this->expectExceptionMessage('Card declined by issuer.');
        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1');
    }

    public function testCapturePaymentThrowsAuthorizationExpiredExceptionWhenTheMessageIndicatesExpiry(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 400, 'body' => json_encode(['message' => 'The authorization has expired.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(AuthorizationExpiredException::class);
        $this->expectExceptionMessage('The authorization has expired.');
        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1');
    }

    public function testCapturePaymentThrowsPaymentAlreadyCapturedExceptionWhenTheMessageIndicatesAlreadyCaptured(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 400, 'body' => json_encode(['message' => 'This payment has already been captured.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(PaymentAlreadyCapturedException::class);
        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1');
    }

    public function testCapturePaymentThrowsPaymentNotCapturableExceptionWhenTheMessageIndicatesNotCapturable(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 400, 'body' => json_encode(['errorCategory' => 'RESSOURCE_ERROR', 'message' => 'Reference authorization not capturable.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(PaymentNotCapturableException::class);
        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1', 500);
    }

    public function testCapturePaymentThrowsAmountExceedsAvailableExceptionWhenTheMessageIndicatesExceedingAmount(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 400, 'body' => json_encode(['message' => 'The amount exceeds the amount still available.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(AmountExceedsAvailableException::class);
        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1', 500);
    }

    public function testCapturePaymentThrowsApiExceptionOnNonSuccessStatusWithNoRecognizedSignal(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 500, 'body' => '{"message":"boom"}']);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API capture request for payment "pay_123" failed with HTTP status 500.');
        $this->expectExceptionCode(500);
        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1');
    }

    /**
     * A 2xx HTTP status alone does not mean the operation succeeded: the Unified API can return
     * HTTP 200 with a non-"0000" execCode for a capture an authorization does not allow more than
     * one of, which must still be treated as a failure rather than returned to the caller as a
     * successful CaptureOutput.
     */
    public function testCapturePaymentThrowsMultipleCaptureNotAllowedExceptionWhenA200ResponseSignalsADuplicateExecCode(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => json_encode(['execCode' => '4011', 'message' => 'Duplicate request.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(MultipleCaptureNotAllowedException::class);
        $this->expectExceptionMessage('Duplicate request.');
        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1');
    }

    public function testCapturePaymentThrowsApiExceptionWhenA200ResponseHasANonSuccessExecCodeWithNoRecognizedMessage(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => json_encode(['execCode' => '5000', 'message' => 'Unexpected system error.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API capture request for payment "pay_123" failed with HTTP status 200 (execCode "5000").');
        $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1');
    }

    public function testCapturePaymentRetriesOnceWithAFreshTokenWhenTheCachedOneIsRejected(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(Mockery::any(), Mockery::any(), ['Authorization' => 'Bearer stale-jwt', 'Content-Type' => 'application/json'])
            ->andReturn(['status' => 401, 'body' => '{"error":"invalid_token"}']);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(Mockery::any(), Mockery::any(), ['Authorization' => 'Bearer fresh-jwt', 'Content-Type' => 'application/json'])
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingRefresh());

        $result = $service->capturePayment('pay_123', 'acc_123', 'order_1', 'Capture for order order_1');

        self::assertSame(200, $result->status);
    }

    public function testCancelPaymentReturnsOutputOnAFullCancellation(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/void',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Cancellation for order order_1',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => json_encode(['amount' => 1000, 'requestedAmount' => 1000])]);

        $service = $this->makeService($httpClient);

        $result = $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1');

        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (kept as a regression guard, not removed)
        self::assertInstanceOf(CancellationOutput::class, $result);
        self::assertSame(200, $result->status);
        self::assertSame(1000, $result->cancelledAmount);
        self::assertSame(0, $result->remainingCancellableAmount);
    }

    public function testCancelPaymentIncludesAmountInTheBodyForAPartialCancellation(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/void',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Cancellation for order order_1',
                    'amount' => 400,
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => json_encode(['amount' => 400, 'requestedAmount' => 1000])]);

        $service = $this->makeService($httpClient);

        $result = $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1', 400);

        self::assertSame(400, $result->cancelledAmount);
        self::assertSame(600, $result->remainingCancellableAmount);
    }

    public function testCancelPaymentIncludesCurrencyInTheBodyWhenGiven(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/void',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Cancellation for order order_1',
                    'amount' => 400,
                    'currency' => 'USD',
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1', 400, null, 'USD');
    }

    public function testCancelPaymentOmitsCurrencyFromTheBodyWhenItIsAnEmptyString(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(
                'https://api.payplug.com/api/payment-gateway/payments/pay_123/void',
                [
                    'account' => ['id' => 'acc_123'],
                    'orderId' => 'order_1',
                    'description' => 'Cancellation for order order_1',
                    'amount' => 400,
                ],
                ['Authorization' => 'Bearer cached-jwt', 'Content-Type' => 'application/json']
            )
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1', 400, null, '');
    }

    public function testCancelPaymentThrowsInvalidCancellationRequestExceptionForAnEmptyOrderIdBeforeAnyNetworkCall(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(InvalidCancellationRequestException::class);
        $this->expectExceptionMessage('orderId must not be empty.');
        $service->cancelPayment('pay_123', 'acc_123', '', 'Cancellation for order order_1');
    }

    public function testCancelPaymentThrowsInvalidCancellationRequestExceptionForAnEmptyDescriptionBeforeAnyNetworkCall(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(InvalidCancellationRequestException::class);
        $this->expectExceptionMessage('description must not be empty.');
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', '');
    }

    /**
     * @dataProvider nonPositiveAmountProvider
     */
    public function testCancelPaymentThrowsCancellationAmountExceptionForANonPositiveAmountBeforeAnyNetworkCall(int $amount): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldNotReceive('postJson');

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingNoInteraction());

        $this->expectException(CancellationAmountException::class);
        $this->expectExceptionMessage('amount must be greater than zero.');
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1', $amount);
    }

    public function testCancelPaymentThrowsPaymentNotFoundExceptionOnA404(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 404, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $this->expectException(PaymentNotFoundException::class);
        $this->expectExceptionCode(404);
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1');
    }

    public function testCancelPaymentThrowsOperationConflictExceptionOnA409(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 409, 'body' => '{}']);

        $service = $this->makeService($httpClient);

        $this->expectException(OperationConflictException::class);
        $this->expectExceptionCode(409);
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1');
    }

    /**
     * A partial cancellation attempt (amount given) rejected with errorCategory
     * "INVALID_REQUEST" is normalized to the dedicated exception rather than a generic
     * ApiException, so a CMS plugin can surface "your contract does not support a partial
     * cancellation" explicitly instead of a bare HTTP failure.
     */
    public function testCancelPaymentThrowsPartialCancellationNotAllowedExceptionForAPartialAmountRejectedAsInvalidRequest(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 403, 'body' => json_encode(['errorCategory' => 'INVALID_REQUEST', 'message' => 'The operation is not allowed.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(PartialCancellationNotAllowedException::class);
        $this->expectExceptionMessage('The operation is not allowed.');
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1', 400);
    }

    /**
     * The same errorCategory on a *full* cancellation (no amount given) is not a partial-specific
     * rejection, so it must not be misreported as one — it falls through to the generic
     * ApiException instead.
     */
    public function testCancelPaymentDoesNotThrowPartialCancellationNotAllowedExceptionForAFullCancellation(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 403, 'body' => json_encode(['errorCategory' => 'INVALID_REQUEST', 'message' => 'The operation is not allowed.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1');
    }

    public function testCancelPaymentThrowsPaymentAlreadyCancelledExceptionWhenTheMessageIndicatesAlreadyCancelled(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 400, 'body' => json_encode(['message' => 'This payment has already been cancelled.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(PaymentAlreadyCancelledException::class);
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1');
    }

    public function testCancelPaymentThrowsPaymentNotVoidableExceptionWhenTheMessageIndicatesNotVoidable(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 400, 'body' => json_encode(['errorCategory' => 'RESSOURCE_ERROR', 'message' => 'The reference transaction is not voidable.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(PaymentNotVoidableException::class);
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1');
    }

    /**
     * The exact same "not voidable" message is returned by the real API whether the payment was
     * already captured or already cancelled — this proves the two are indistinguishable from that
     * message alone, which is why it maps to a dedicated PaymentNotVoidableException rather than
     * PaymentAlreadyCapturedException or PaymentAlreadyCancelledException.
     */
    public function testCancelPaymentThrowsPaymentNotVoidableExceptionOnASecondVoidOfAnAlreadyVoidedPayment(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 400, 'body' => json_encode(['errorCategory' => 'RESSOURCE_ERROR', 'message' => 'The reference transaction is not voidable.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(PaymentNotVoidableException::class);
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1');
    }

    public function testCancelPaymentThrowsCardOperationExceptionForAnIssuerRefusalExecCode(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 400, 'body' => json_encode(['execCode' => '4002', 'message' => 'Refused by issuer.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(CardOperationException::class);
        $this->expectExceptionMessage('Refused by issuer.');
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1');
    }

    public function testCancelPaymentThrowsOperationConflictExceptionWhenA200ResponseSignalsADuplicateExecCode(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => json_encode(['execCode' => '4011', 'message' => 'Duplicate request.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(OperationConflictException::class);
        $this->expectExceptionMessage('Duplicate request.');
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1');
    }

    public function testCancelPaymentThrowsApiExceptionWhenA200ResponseHasANonSuccessExecCodeWithNoRecognizedMessage(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')->once()->andReturn(['status' => 200, 'body' => json_encode(['execCode' => '5000', 'message' => 'Unexpected system error.'])]);

        $service = $this->makeService($httpClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unified API cancellation request for payment "pay_123" failed with HTTP status 200 (execCode "5000").');
        $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1');
    }

    public function testCancelPaymentRetriesOnceWithAFreshTokenWhenTheCachedOneIsRejected(): void
    {
        $httpClient = Mockery::mock(IUnifiedApiHttpClient::class);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(Mockery::any(), Mockery::any(), ['Authorization' => 'Bearer stale-jwt', 'Content-Type' => 'application/json'])
            ->andReturn(['status' => 401, 'body' => '{"error":"invalid_token"}']);
        $httpClient->shouldReceive('postJson')
            ->once()
            ->with(Mockery::any(), Mockery::any(), ['Authorization' => 'Bearer fresh-jwt', 'Content-Type' => 'application/json'])
            ->andReturn(['status' => 200, 'body' => '{}']);

        $service = $this->makeService($httpClient, 'https://api.payplug.com', $this->makeTokenManagerExpectingRefresh());

        $result = $service->cancelPayment('pay_123', 'acc_123', 'order_1', 'Cancellation for order order_1');

        self::assertSame(200, $result->status);
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
