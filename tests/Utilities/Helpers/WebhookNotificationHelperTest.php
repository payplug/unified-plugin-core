<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Utilities\Helpers;

use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PayplugUnifiedCore\Models\PaymentOutcome;
use PayplugUnifiedCore\Utilities\Helpers\WebhookNotificationHelper;
use PHPUnit\Framework\TestCase;

final class WebhookNotificationHelperTest extends TestCase
{
    public function testVerifySignaturePassesWhenAuthorizationHeaderMatches(): void
    {
        WebhookNotificationHelper::verifySignature(['Authorization' => 'Bearer secret123'], 'Bearer secret123');

        $this->expectNotToPerformAssertions();
    }

    public function testVerifySignatureIsCaseInsensitiveOnTheHeaderName(): void
    {
        WebhookNotificationHelper::verifySignature(['authorization' => 'Bearer secret123'], 'Bearer secret123');

        $this->expectNotToPerformAssertions();
    }

    public function testVerifySignatureThrowsWhenAuthorizationHeaderIsMissing(): void
    {
        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('Webhook notification is missing the Authorization header.');

        WebhookNotificationHelper::verifySignature(['Content-Type' => 'application/json'], 'Bearer secret123');
    }

    public function testVerifySignatureThrowsWhenAuthorizationHeaderDoesNotMatch(): void
    {
        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('Webhook notification signature does not match.');

        WebhookNotificationHelper::verifySignature(['Authorization' => 'Bearer wrong'], 'Bearer secret123');
    }

    public function testParseReturnsOperationDataForAValidPaymentOperationNotification(): void
    {
        $rawBody = json_encode([
            'id' => '3421789',
            'type' => 'payment',
            'execCode' => '0000',
            'message' => 'Operation succeeded',
            'amount' => 4999,
            'currency' => 'EUR',
            'descriptor' => 'MERCHANT SHOP',
            'orderId' => 'order_456',
            'stan' => '000123',
            'account' => ['id' => 'acc_789'],
            'customer' => ['id' => 'cust_001', 'email' => 'buyer@example.com'],
            'paymentMethod' => [
                'card' => ['code6x4' => '497010******1234', 'type' => 'visa'],
                'details' => ['validityDate' => '1225'],
            ],
            'authentication' => ['status' => 'Y', 'globalStatus' => 'AUTHENTICATION_SUCCESSFUL', 'mode' => 'CHALLENGE'],
        ]);

        $operationData = WebhookNotificationHelper::parse(['Authorization' => 'Bearer secret123'], (string) $rawBody, 'Bearer secret123');

        self::assertSame('3421789', $operationData->operationId);
        self::assertSame('0000', $operationData->execCode);
        self::assertSame(PaymentOutcome::PAID, $operationData->outcome);
        self::assertSame(4999, $operationData->amount);
        self::assertSame('order_456', $operationData->orderId);
    }

    public function testParseMapsANonSuccessExecCodeToFailedOutcome(): void
    {
        $rawBody = json_encode(['id' => 'op_1', 'execCode' => '4008', 'orderId' => 'order_456', 'amount' => 4999]);

        $operationData = WebhookNotificationHelper::parse(['Authorization' => 'Bearer secret123'], (string) $rawBody, 'Bearer secret123');

        self::assertSame(PaymentOutcome::FAILED, $operationData->outcome);
    }

    public function testParseThrowsSignatureExceptionBeforeAttemptingToParseAMalformedBody(): void
    {
        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('Webhook notification is missing the Authorization header.');

        WebhookNotificationHelper::parse([], 'not valid json {{{', 'Bearer secret123');
    }

    public function testParseThrowsWhenBodyIsNotValidJson(): void
    {
        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('Webhook notification payload is malformed.');

        WebhookNotificationHelper::parse(['Authorization' => 'Bearer secret123'], 'not valid json {{{', 'Bearer secret123');
    }

    public function testParseThrowsWhenARequiredFieldIsMissing(): void
    {
        $rawBody = json_encode(['id' => 'op_1', 'execCode' => '0000', 'amount' => 4999]);

        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('Webhook notification payload is malformed.');

        WebhookNotificationHelper::parse(['Authorization' => 'Bearer secret123'], (string) $rawBody, 'Bearer secret123');
    }

    public function testParseWrapsInvalidOperationDataExceptionIntoInvalidNotificationException(): void
    {
        $rawBody = json_encode(['id' => 'op_1', 'execCode' => '0000', 'orderId' => '', 'amount' => 4999]);

        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('Webhook notification payload is invalid.');

        try {
            WebhookNotificationHelper::parse(['Authorization' => 'Bearer secret123'], (string) $rawBody, 'Bearer secret123');
        } catch (InvalidNotificationException $e) {
            self::assertNotNull($e->getPrevious());

            throw $e;
        }
    }
}
