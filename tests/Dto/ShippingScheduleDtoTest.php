<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Dto;

use PayplugUnifiedCore\Dto\ShippingScheduleDto;
use PHPUnit\Framework\TestCase;

final class ShippingScheduleDtoTest extends TestCase
{
    public function testConstructorDefaultsAllFieldsToNull(): void
    {
        $schedule = new ShippingScheduleDto();

        self::assertNull($schedule->addressType);
        self::assertNull($schedule->timeFrame);
        self::assertNull($schedule->addressDate);
    }

    public function testConstructorAssignsAllProperties(): void
    {
        $schedule = new ShippingScheduleDto('billing-shipping', 'lessThanOneHour', '2024-01-01');

        self::assertSame('billing-shipping', $schedule->addressType);
        self::assertSame('lessThanOneHour', $schedule->timeFrame);
        self::assertSame('2024-01-01', $schedule->addressDate);
    }

    public function testToArrayReturnsExpectedShapeWhenEveryFieldIsProvided(): void
    {
        $schedule = new ShippingScheduleDto('billing-shipping', 'lessThanOneHour', '2024-01-01');

        self::assertSame([
            'addressType' => 'billing-shipping',
            'timeFrame' => 'lessThanOneHour',
            'addressDate' => '2024-01-01',
        ], $schedule->toArray());
    }

    public function testToArrayOmitsFieldsThatAreNull(): void
    {
        $schedule = new ShippingScheduleDto('billing-shipping', null, '2024-01-01');

        self::assertSame([
            'addressType' => 'billing-shipping',
            'addressDate' => '2024-01-01',
        ], $schedule->toArray());
    }

    public function testToArrayReturnsEmptyArrayWhenNoFieldIsProvided(): void
    {
        self::assertSame([], (new ShippingScheduleDto())->toArray());
    }
}
