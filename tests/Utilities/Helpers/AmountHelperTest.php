<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Utilities\Helpers;

use PayplugUnifiedCore\Utilities\Helpers\AmountHelper;
use PHPUnit\Framework\TestCase;

final class AmountHelperTest extends TestCase
{
    public function testToCentsConvertsWholeEuroAmount(): void
    {
        self::assertSame(1000, AmountHelper::toCents(10.0));
    }

    public function testToCentsCorrectsFloatingPointImprecision(): void
    {
        // 19.99 * 100 evaluates to 1998.9999999999998 in raw PHP float math;
        // toCents() must still return the correct 1999 cents.
        self::assertSame(1999, AmountHelper::toCents(19.99));
    }

    public function testToCentsRoundsExactHalfCentBoundaryAwayFromZero(): void
    {
        self::assertSame(2000, AmountHelper::toCents(19.995));
    }

    public function testToCentsRoundsSubCentPrecisionFromVatCalculation(): void
    {
        // 80.55 HT + 36.00 HT delivery, +21% VAT: 116.55 * 1.21 = 141.0255 before
        // rounding to the nearest cent.
        self::assertSame(14103, AmountHelper::toCents(141.0255));
    }

    public function testToCentsHandlesZero(): void
    {
        self::assertSame(0, AmountHelper::toCents(0.0));
    }

    public function testToCentsHandlesNegativeAmountForRefunds(): void
    {
        self::assertSame(-1050, AmountHelper::toCents(-10.5));
    }

    public function testToCentsHandlesLargeAmount(): void
    {
        self::assertSame(99999999, AmountHelper::toCents(999999.99));
    }

    public function testFromCentsConvertsWholeEuroAmount(): void
    {
        self::assertSame(19.99, AmountHelper::fromCents(1999));
    }

    public function testFromCentsHandlesZero(): void
    {
        self::assertSame(0.0, AmountHelper::fromCents(0));
    }

    public function testFromCentsHandlesNegativeAmountForRefunds(): void
    {
        self::assertSame(-10.5, AmountHelper::fromCents(-1050));
    }

    public function testFromCentsHandlesLargeAmount(): void
    {
        self::assertSame(999999.99, AmountHelper::fromCents(99999999));
    }

    /**
     * @dataProvider roundTripAmountProvider
     */
    public function testRoundTripConversionPreservesAmount(float $amount): void
    {
        self::assertSame($amount, AmountHelper::fromCents(AmountHelper::toCents($amount)));
    }

    /**
     * @return array<int, array<int, float>>
     */
    public function roundTripAmountProvider(): array
    {
        return [
            [0.0],
            [1.0],
            [19.99],
            [100.5],
            [-10.5],
            [999999.99],
        ];
    }
}
