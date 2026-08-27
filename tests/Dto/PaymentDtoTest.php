<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Dto;

use PayplugUnifiedCore\Dto\AddressDto;
use PayplugUnifiedCore\Dto\BillingDto;
use PayplugUnifiedCore\Dto\BrowserDto;
use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Dto\ContactDto;
use PayplugUnifiedCore\Dto\CustomerDto;
use PayplugUnifiedCore\Dto\PaymentDto;
use PayplugUnifiedCore\Dto\ShippingDto;
use PHPUnit\Framework\TestCase;

final class PaymentDtoTest extends TestCase
{
    public function testConstructorAssignsAllPropertiesWhenEveryOptionalFieldIsProvided(): void
    {
        $common = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');
        $browser = new BrowserDto('10.1.1.1', 'https://shop.example.com/cart', 'Mozilla/5.0');
        $customer = new CustomerDto('john.snow', 'john.snow@example.com');
        $paymentMethod = ['details' => ['selectedBrand' => 'VISA']];

        $dto = new PaymentDto($common, 'alias_789', 'ONE_CLICK', $browser, $customer, $paymentMethod);

        self::assertSame($common, $dto->common);
        self::assertSame('alias_789', $dto->aliasId);
        self::assertSame('ONE_CLICK', $dto->recurringMode);
        self::assertSame($browser, $dto->browser);
        self::assertSame($customer, $dto->customer);
        self::assertSame($paymentMethod, $dto->paymentMethod);
    }

    public function testConstructorDefaultsOptionalFieldsToNull(): void
    {
        $dto = new PaymentDto(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'), 'alias_789', 'ONE_CLICK');

        self::assertNull($dto->browser);
        self::assertNull($dto->customer);
        self::assertNull($dto->paymentMethod);
    }

    public function testCreatePayloadBodyIncludesRequiredFieldsAndDefaultsCaptureToTrue(): void
    {
        $dto = new PaymentDto(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'), 'alias_789', 'ONE_CLICK');

        self::assertSame([
            'account' => ['id' => 'acc_123'],
            'submerchantExternalId' => 'submerchant_789',
            'amount' => 1000,
            'currency' => 'EUR',
            'orderId' => 'order_456',
            'description' => null,
            'capture' => true,
            'paymentMethod' => ['id' => 'alias_789'],
            'recurringMode' => 'ONE_CLICK',
        ], $dto->createPayloadBody());
    }

    public function testCreatePayloadBodyReflectsCaptureFalse(): void
    {
        $common = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');
        $common->capture = false;

        $dto = new PaymentDto($common, 'alias_789', 'ONE_CLICK');

        self::assertFalse($dto->createPayloadBody()['capture']);
    }

    public function testCreatePayloadBodyMergesAliasIdWithCallerSuppliedPaymentMethodDetails(): void
    {
        $dto = new PaymentDto(
            new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'),
            'alias_789',
            'ONE_CLICK',
            null,
            null,
            ['details' => ['selectedBrand' => 'VISA']]
        );

        self::assertSame(
            ['details' => ['selectedBrand' => 'VISA'], 'id' => 'alias_789'],
            $dto->createPayloadBody()['paymentMethod']
        );
    }

    public function testCreatePayloadBodyIncludesOptionalFieldsWhenProvided(): void
    {
        $common = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');
        $common->description = 'Order #456';
        $common->descriptor = 'MY SHOP Order #456';
        $common->notificationUrl = 'https://shop.example.com/payplug/notification';
        $common->extraData = 'internal_ref_789';
        $common->billing = new BillingDto(new AddressDto('1 rue de Rivoli', 'Paris', 'FR', 'IDF', '75001'), new ContactDto('John', 'Snow'));
        $common->shipping = new ShippingDto(new AddressDto('2 rue de Rivoli', 'Paris', 'FR', 'IDF', '75001'), new ContactDto('John', 'Snow'));
        $common->successUrl = 'https://shop.example.com/pay/success';
        $common->cancelUrl = 'https://shop.example.com/pay/cancel';

        $dto = new PaymentDto(
            $common,
            'alias_789',
            'ONE_CLICK',
            new BrowserDto('10.1.1.1', 'https://shop.example.com/cart', 'Mozilla/5.0'),
            new CustomerDto('john.snow', 'john.snow@example.com')
        );

        self::assertSame([
            'account' => ['id' => 'acc_123'],
            'submerchantExternalId' => 'submerchant_789',
            'amount' => 1000,
            'currency' => 'EUR',
            'orderId' => 'order_456',
            'description' => 'Order #456',
            'capture' => true,
            'paymentMethod' => ['id' => 'alias_789'],
            'recurringMode' => 'ONE_CLICK',
            'browser' => ['ip' => '10.1.1.1', 'referrer' => 'https://shop.example.com/cart', 'userAgent' => 'Mozilla/5.0'],
            'customer' => ['id' => 'john.snow', 'email' => 'john.snow@example.com'],
            'descriptor' => 'MY SHOP Order #456',
            'notificationUrl' => 'https://shop.example.com/payplug/notification',
            'extraData' => 'internal_ref_789',
            'billing' => ['address' => ['line' => '1 rue de Rivoli', 'city' => 'Paris', 'country' => 'FR', 'state' => 'IDF', 'zipCode' => '75001'], 'firstName' => 'John', 'lastName' => 'Snow'],
            'shipping' => ['address' => ['line' => '2 rue de Rivoli', 'city' => 'Paris', 'country' => 'FR', 'state' => 'IDF', 'zipCode' => '75001'], 'firstName' => 'John', 'lastName' => 'Snow'],
            'redirect' => [
                'successUrl' => 'https://shop.example.com/pay/success',
                'cancelUrl' => 'https://shop.example.com/pay/cancel',
            ],
        ], $dto->createPayloadBody());
    }

    public function testCreatePayloadBodyOmitsAllOptionalFieldsWhenNotProvided(): void
    {
        $dto = new PaymentDto(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'), 'alias_789', 'ONE_CLICK');

        $body = $dto->createPayloadBody();

        self::assertArrayNotHasKey('browser', $body);
        self::assertArrayNotHasKey('customer', $body);
        self::assertArrayNotHasKey('descriptor', $body);
        self::assertArrayNotHasKey('notificationUrl', $body);
        self::assertArrayNotHasKey('extraData', $body);
        self::assertArrayNotHasKey('billing', $body);
        self::assertArrayNotHasKey('shipping', $body);
        self::assertArrayNotHasKey('redirect', $body);
    }

    public function testCreatePayloadBodyAlwaysIncludesTheDescriptionKeyEvenWhenNull(): void
    {
        $dto = new PaymentDto(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'), 'alias_789', 'ONE_CLICK');

        $body = $dto->createPayloadBody();

        self::assertArrayHasKey('description', $body);
        self::assertNull($body['description']);
    }
}
