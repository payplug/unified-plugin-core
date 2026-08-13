<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Dto;

use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PHPUnit\Framework\TestCase;

final class CommonFieldsDtoTest extends TestCase
{
    public function testConstructorAssignsRequiredProperties(): void
    {
        $common = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');

        self::assertSame('acc_123', $common->accountId);
        self::assertSame(1000, $common->amount);
        self::assertSame('EUR', $common->currency);
        self::assertSame('order_456', $common->orderId);
        self::assertSame('submerchant_789', $common->submerchantExternalId);
    }

    public function testConstructorDefaultsOptionalProperties(): void
    {
        $common = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');

        self::assertNull($common->description);
        self::assertTrue($common->capture);
        self::assertNull($common->descriptor);
        self::assertNull($common->notificationUrl);
        self::assertNull($common->extraData);
    }

    public function testOptionalPropertiesAreSettableAfterConstruction(): void
    {
        $common = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');

        $common->description = 'Order #456';
        $common->capture = false;
        $common->descriptor = 'MY SHOP Order #456';
        $common->notificationUrl = 'https://shop.example.com/payplug/notification';
        $common->extraData = 'internal_ref_789';

        self::assertSame('Order #456', $common->description);
        self::assertFalse($common->capture);
        self::assertSame('MY SHOP Order #456', $common->descriptor);
        self::assertSame('https://shop.example.com/payplug/notification', $common->notificationUrl);
        self::assertSame('internal_ref_789', $common->extraData);
    }
}
