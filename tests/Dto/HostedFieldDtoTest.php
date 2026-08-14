<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Dto;

use PayplugUnifiedCore\Dto\BrowserDto;
use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Dto\CustomerDto;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PHPUnit\Framework\TestCase;

final class HostedFieldDtoTest extends TestCase
{
    public function testConstructorAssignsAllPropertiesWhenEveryOptionalFieldIsProvided(): void
    {
        $common = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');
        $common->description = 'Order #456';
        $common->capture = false;
        $common->descriptor = 'MY SHOP Order #456';
        $common->notificationUrl = 'https://shop.example.com/payplug/notification';
        $common->extraData = 'internal_ref_789';

        $browser = new BrowserDto('10.1.1.1', 'https://shop.example.com/cart', 'Mozilla/5.0');
        $customer = new CustomerDto('john.snow', 'john.snow@example.com');
        $paymentMethod = ['details' => ['fullName' => 'John Snow', 'selectedBrand' => 'visa']];

        $dto = new HostedFieldDto($common, 'hf_abc', $browser, $customer, $paymentMethod);

        self::assertSame($common, $dto->common);
        self::assertSame('hf_abc', $dto->hfToken);
        self::assertSame($browser, $dto->browser);
        self::assertSame($customer, $dto->customer);
        self::assertSame($paymentMethod, $dto->paymentMethod);
    }

    public function testConstructorDefaultsOptionalFieldsToNull(): void
    {
        $dto = new HostedFieldDto(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'), 'hf_abc');

        self::assertNull($dto->browser);
        self::assertNull($dto->customer);
        self::assertNull($dto->paymentMethod);
    }

    public function testCreatePayloadBodyIncludesRequiredFieldsAndDefaultsCaptureToTrue(): void
    {
        $dto = new HostedFieldDto(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'), 'hf_abc');

        self::assertSame([
            'account' => ['id' => 'acc_123'],
            'submerchantExternalId' => 'submerchant_789',
            'amount' => 1000,
            'currency' => 'EUR',
            'orderId' => 'order_456',
            'capture' => true,
            'hfToken' => 'hf_abc',
        ], $dto->createPayloadBody());
    }

    public function testCreatePayloadBodyReflectsCaptureFalse(): void
    {
        $common = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');
        $common->capture = false;

        $dto = new HostedFieldDto($common, 'hf_abc');

        self::assertFalse($dto->createPayloadBody()['capture']);
    }

    public function testCreatePayloadBodyIncludesOptionalFieldsWhenProvided(): void
    {
        $common = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');
        $common->description = 'Order #456';
        $common->descriptor = 'MY SHOP Order #456';
        $common->notificationUrl = 'https://shop.example.com/payplug/notification';
        $common->extraData = 'internal_ref_789';
        $common->successUrl = 'https://shop.example.com/pay/success';
        $common->cancelUrl = 'https://shop.example.com/pay/cancel';

        $dto = new HostedFieldDto(
            $common,
            'hf_abc',
            new BrowserDto('10.1.1.1', 'https://shop.example.com/cart', 'Mozilla/5.0'),
            new CustomerDto('john.snow', 'john.snow@example.com'),
            ['details' => ['fullName' => 'John Snow', 'selectedBrand' => 'visa']]
        );

        self::assertSame([
            'account' => ['id' => 'acc_123'],
            'submerchantExternalId' => 'submerchant_789',
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
            'redirect' => [
                'successUrl' => 'https://shop.example.com/pay/success',
                'cancelUrl' => 'https://shop.example.com/pay/cancel',
            ],
        ], $dto->createPayloadBody());
    }

    public function testCreatePayloadBodyOmitsRedirectWhenNeitherSuccessNorCancelUrlIsProvided(): void
    {
        $dto = new HostedFieldDto(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'), 'hf_abc');

        self::assertArrayNotHasKey('redirect', $dto->createPayloadBody());
    }

    public function testCreatePayloadBodyIncludesRedirectWithOnlySuccessUrlWhenCancelUrlIsNotProvided(): void
    {
        $common = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');
        $common->successUrl = 'https://shop.example.com/pay/success';

        $dto = new HostedFieldDto($common, 'hf_abc');

        self::assertSame(['successUrl' => 'https://shop.example.com/pay/success'], $dto->createPayloadBody()['redirect']);
    }

    public function testCreatePayloadBodyIncludesRedirectWithOnlyCancelUrlWhenSuccessUrlIsNotProvided(): void
    {
        $common = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');
        $common->cancelUrl = 'https://shop.example.com/pay/cancel';

        $dto = new HostedFieldDto($common, 'hf_abc');

        self::assertSame(['cancelUrl' => 'https://shop.example.com/pay/cancel'], $dto->createPayloadBody()['redirect']);
    }

    public function testCreatePayloadBodyOmitsPaymentMethodWhenItIsAnEmptyArray(): void
    {
        $dto = new HostedFieldDto(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'), 'hf_abc', null, null, []);

        self::assertArrayNotHasKey('paymentMethod', $dto->createPayloadBody());
    }

    public function testCreatePayloadBodyOmitsAllOptionalFieldsWhenNotProvided(): void
    {
        $dto = new HostedFieldDto(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'), 'hf_abc');

        $body = $dto->createPayloadBody();

        self::assertArrayNotHasKey('paymentMethod', $body);
        self::assertArrayNotHasKey('browser', $body);
        self::assertArrayNotHasKey('customer', $body);
        self::assertArrayNotHasKey('description', $body);
        self::assertArrayNotHasKey('descriptor', $body);
        self::assertArrayNotHasKey('notificationUrl', $body);
        self::assertArrayNotHasKey('extraData', $body);
        self::assertArrayNotHasKey('redirect', $body);
    }

    /**
     * Unlike the other createPayloadBody() tests, asserts on real json_encode() output, not PHP
     * array equality — that's what catches a PHP array being structurally right but serializing to
     * the wrong JSON shape.
     */
    public function testCreatePayloadBodyJsonEncodedOutputOmitsPaymentMethodEntirelyWhenNotProvided(): void
    {
        $dto = new HostedFieldDto(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'), 'hf_abc');

        self::assertStringNotContainsString('paymentMethod', (string) json_encode($dto->createPayloadBody()));
    }

    public function testCreatePayloadBodyJsonEncodedOutputSerializesPaymentMethodAsAnObject(): void
    {
        $dto = new HostedFieldDto(
            new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'),
            'hf_abc',
            null,
            null,
            ['details' => ['fullName' => 'John Snow']]
        );

        self::assertStringContainsString('"paymentMethod":{"details":{"fullName":"John Snow"}}', (string) json_encode($dto->createPayloadBody()));
    }
}
