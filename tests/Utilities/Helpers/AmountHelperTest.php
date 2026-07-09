<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Utilities\Helpers;

use PayplugUnifiedCore\Utilities\Helpers\AmountHelper;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\RecursionContext\InvalidArgumentException;

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

    public function testToCentsRoundsNegativeExactHalfCentBoundaryAwayFromZero(): void
    {
        self::assertSame(-2000, AmountHelper::toCents(-19.995));
    }

    /**
     * @dataProvider ambiguousHalfCentBoundaryModeProvider
     *
     * @param 1|2|3|4 $mode
     * @param int $expectedCents
     * @throws ExpectationFailedException
     * @throws InvalidArgumentException
     */
    public function testToCentsAppliesExplicitRoundingModeOnAmbiguousBoundary(int $mode, int $expectedCents): void
    {
        // 19.995 lands exactly on a half-cent boundary, so the outcome genuinely
        // depends on the caller's chosen mode — e.g. a merchant's CMS-configured
        // rounding preference — unlike an already-decided 2-decimal amount.
        self::assertSame($expectedCents, AmountHelper::toCents(19.995, $mode));
    }

    /**
     * @return array<string, array<int, int>>
     */
    public function ambiguousHalfCentBoundaryModeProvider(): array
    {
        return [
            'HALF_UP rounds away from zero' => [PHP_ROUND_HALF_UP, 2000],
            'HALF_DOWN rounds toward zero' => [PHP_ROUND_HALF_DOWN, 1999],
            'HALF_EVEN rounds to the nearest even cent' => [PHP_ROUND_HALF_EVEN, 2000],
            'HALF_ODD rounds to the nearest odd cent' => [PHP_ROUND_HALF_ODD, 1999],
        ];
    }

    public function testToCentsModeHasNoEffectOnAlreadyDecidedAmount(): void
    {
        // 19.99 is already a clean 2-decimal amount with no ambiguity left to
        // resolve, so every rounding mode must agree.
        self::assertSame(1999, AmountHelper::toCents(19.99, PHP_ROUND_HALF_DOWN));
        self::assertSame(1999, AmountHelper::toCents(19.99, PHP_ROUND_HALF_EVEN));
    }

    public function testToCentsCorrectsClassicBinaryRoundingTrap(): void
    {
        // 1.005 * 100 evaluates to 100.49999999999999 in raw PHP float math — the
        // single most well-known floating-point rounding trap (naive rounding would
        // give 100 instead of 101). round() still returns 101 thanks to PHP's
        // built-in precision correction, and toCents() must preserve that.
        self::assertSame(101, AmountHelper::toCents(1.005));
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

    public function testFromCentsConvertsWholeCentsToRoundEuroAmount(): void
    {
        self::assertSame(20.0, AmountHelper::fromCents(2000));
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
