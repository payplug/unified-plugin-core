<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Utilities\Helpers;

use PayplugUnifiedCore\Exceptions\InvalidCurrencyException;
use PayplugUnifiedCore\Utilities\Helpers\AmountHelper;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\RecursionContext\InvalidArgumentException;

final class AmountHelperTest extends TestCase
{
    public function testToCentsConvertsWholeEuroAmount(): void
    {
        self::assertSame(1000, AmountHelper::toCents(10.0, 'EUR'));
    }

    public function testToCentsCorrectsFloatingPointImprecision(): void
    {
        // 19.99 * 100 evaluates to 1998.9999999999998 in raw PHP float math;
        // toCents() must still return the correct 1999 cents.
        self::assertSame(1999, AmountHelper::toCents(19.99, 'EUR'));
    }

    public function testToCentsRoundsExactHalfCentBoundaryAwayFromZero(): void
    {
        self::assertSame(2000, AmountHelper::toCents(19.995, 'EUR'));
    }

    public function testToCentsRoundsNegativeExactHalfCentBoundaryAwayFromZero(): void
    {
        self::assertSame(-2000, AmountHelper::toCents(-19.995, 'EUR'));
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
        self::assertSame($expectedCents, AmountHelper::toCents(19.995, 'EUR', $mode));
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
        self::assertSame(1999, AmountHelper::toCents(19.99, 'EUR', PHP_ROUND_HALF_DOWN));
        self::assertSame(1999, AmountHelper::toCents(19.99, 'EUR', PHP_ROUND_HALF_EVEN));
    }

    public function testToCentsCorrectsClassicBinaryRoundingTrap(): void
    {
        // 1.005 * 100 evaluates to 100.49999999999999 in raw PHP float math — the
        // single most well-known floating-point rounding trap (naive rounding would
        // give 100 instead of 101). round() still returns 101 thanks to PHP's
        // built-in precision correction, and toCents() must preserve that.
        self::assertSame(101, AmountHelper::toCents(1.005, 'EUR'));
    }

    public function testToCentsRoundsSubCentPrecisionFromVatCalculation(): void
    {
        // 80.55 HT + 36.00 HT delivery, +21% VAT: 116.55 * 1.21 = 141.0255 before
        // rounding to the nearest cent.
        self::assertSame(14103, AmountHelper::toCents(141.0255, 'EUR'));
    }

    public function testToCentsHandlesZero(): void
    {
        self::assertSame(0, AmountHelper::toCents(0.0, 'EUR'));
    }

    public function testToCentsHandlesNegativeAmountForRefunds(): void
    {
        self::assertSame(-1050, AmountHelper::toCents(-10.5, 'EUR'));
    }

    public function testToCentsHandlesLargeAmount(): void
    {
        self::assertSame(99999999, AmountHelper::toCents(999999.99, 'EUR'));
    }

    public function testToCentsUsesTwoDecimalsForUsd(): void
    {
        self::assertSame(4999, AmountHelper::toCents(49.99, 'USD'));
    }

    public function testFromCentsConvertsWholeCentsToRoundEuroAmount(): void
    {
        self::assertSame(20.0, AmountHelper::fromCents(2000, 'EUR'));
    }

    public function testFromCentsHandlesZero(): void
    {
        self::assertSame(0.0, AmountHelper::fromCents(0, 'EUR'));
    }

    public function testFromCentsHandlesNegativeAmountForRefunds(): void
    {
        self::assertSame(-10.5, AmountHelper::fromCents(-1050, 'EUR'));
    }

    public function testFromCentsHandlesLargeAmount(): void
    {
        self::assertSame(999999.99, AmountHelper::fromCents(99999999, 'EUR'));
    }

    public function testFromCentsUsesTwoDecimalsForUsd(): void
    {
        self::assertSame(49.99, AmountHelper::fromCents(4999, 'USD'));
    }

    /**
     * @dataProvider roundTripAmountProvider
     */
    public function testRoundTripConversionPreservesAmount(float $amount): void
    {
        self::assertSame($amount, AmountHelper::fromCents(AmountHelper::toCents($amount, 'EUR'), 'EUR'));
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

    public function testToCentsSendsJpyAmountAsIs(): void
    {
        self::assertSame(1000, AmountHelper::toCents(1000.0, 'JPY'));
    }

    public function testToCentsSendsKrwAmountAsIs(): void
    {
        self::assertSame(50000, AmountHelper::toCents(50000.0, 'KRW'));
    }

    public function testFromCentsReturnsJpyAmountAsIs(): void
    {
        self::assertSame(1000.0, AmountHelper::fromCents(1000, 'JPY'));
    }

    public function testFromCentsReturnsKrwAmountAsIs(): void
    {
        self::assertSame(50000.0, AmountHelper::fromCents(50000, 'KRW'));
    }

    /**
     * @dataProvider zeroDecimalCurrencyProvider
     */
    public function testEveryZeroDecimalCurrencyIsConvertedAsIs(string $currency): void
    {
        self::assertSame(1234, AmountHelper::toCents(1234.0, $currency));
        self::assertSame(1234.0, AmountHelper::fromCents(1234, $currency));
        self::assertSame(1234.0, AmountHelper::fromCents(AmountHelper::toCents(1234.0, $currency), $currency));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function zeroDecimalCurrencyProvider(): array
    {
        $codes = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

        $cases = [];
        foreach ($codes as $code) {
            $cases[$code] = [$code];
        }

        return $cases;
    }

    /**
     * @dataProvider currencyCaseAndWhitespaceProvider
     */
    public function testCurrencyIsCaseInsensitiveAndTrimmed(string $currency, int $expectedMinorUnits): void
    {
        self::assertSame($expectedMinorUnits, AmountHelper::toCents(1000.0, $currency));
        self::assertSame(1000.0, AmountHelper::fromCents($expectedMinorUnits, $currency));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public function currencyCaseAndWhitespaceProvider(): array
    {
        return [
            'lower-case jpy' => ['jpy', 1000],
            'mixed-case Jpy' => ['Jpy', 1000],
            'padded JPY' => [' JPY ', 1000],
            'lower-case padded eur' => [' eur ', 100000],
        ];
    }

    /**
     * @dataProvider unlistedWellFormedCurrencyProvider
     */
    public function testUnlistedWellFormedCurrencyUsesTwoDecimals(string $currency): void
    {
        self::assertSame(1999, AmountHelper::toCents(19.99, $currency));
        self::assertSame(19.99, AmountHelper::fromCents(1999, $currency));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function unlistedWellFormedCurrencyProvider(): array
    {
        return [
            'XXX' => ['XXX'],
            'ZZZ' => ['ZZZ'],
        ];
    }

    /**
     * @dataProvider threeDecimalCurrencyProvider
     */
    public function testToCentsRejectsThreeDecimalCurrency(string $currency): void
    {
        $this->expectException(InvalidCurrencyException::class);
        $this->expectExceptionMessage('not supported');

        AmountHelper::toCents(12.346, $currency);
    }

    /**
     * @dataProvider threeDecimalCurrencyProvider
     */
    public function testFromCentsRejectsThreeDecimalCurrency(string $currency): void
    {
        $this->expectException(InvalidCurrencyException::class);
        $this->expectExceptionMessage('not supported');

        AmountHelper::fromCents(12346, $currency);
    }

    public function testThreeDecimalCurrencyIsRejectedCaseInsensitivelyAfterTrimming(): void
    {
        $this->expectException(InvalidCurrencyException::class);

        AmountHelper::toCents(1.0, ' kwd ');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function threeDecimalCurrencyProvider(): array
    {
        return [
            'BHD' => ['BHD'],
            'IQD' => ['IQD'],
            'JOD' => ['JOD'],
            'KWD' => ['KWD'],
            'LYD' => ['LYD'],
            'OMR' => ['OMR'],
            'TND' => ['TND'],
        ];
    }

    /**
     * @dataProvider zeroDecimalHalfBoundaryModeProvider
     *
     * @param 1|2|3|4 $mode
     */
    public function testToCentsAppliesRoundingModeToZeroDecimalCurrency(float $amount, int $mode, int $expected): void
    {
        self::assertSame($expected, AmountHelper::toCents($amount, 'JPY', $mode));
    }

    /**
     * @return array<string, array{0: float, 1: int, 2: int}>
     */
    public function zeroDecimalHalfBoundaryModeProvider(): array
    {
        return [
            '1000.5 HALF_UP' => [1000.5, PHP_ROUND_HALF_UP, 1001],
            '1000.5 HALF_DOWN' => [1000.5, PHP_ROUND_HALF_DOWN, 1000],
            '1000.5 HALF_EVEN' => [1000.5, PHP_ROUND_HALF_EVEN, 1000],
            '1000.5 HALF_ODD' => [1000.5, PHP_ROUND_HALF_ODD, 1001],
            '1001.5 HALF_UP' => [1001.5, PHP_ROUND_HALF_UP, 1002],
            '1001.5 HALF_DOWN' => [1001.5, PHP_ROUND_HALF_DOWN, 1001],
            '1001.5 HALF_EVEN' => [1001.5, PHP_ROUND_HALF_EVEN, 1002],
            '1001.5 HALF_ODD' => [1001.5, PHP_ROUND_HALF_ODD, 1001],
            '-1000.5 HALF_UP' => [-1000.5, PHP_ROUND_HALF_UP, -1001],
            '999.4 HALF_UP' => [999.4, PHP_ROUND_HALF_UP, 999],
        ];
    }

    public function testToCentsDefaultsToHalfUpForZeroDecimalCurrency(): void
    {
        self::assertSame(1001, AmountHelper::toCents(1000.5, 'JPY'));
    }

    public function testToCentsRejectsEmptyCurrency(): void
    {
        $this->expectException(InvalidCurrencyException::class);
        $this->expectExceptionMessage('currency must not be empty.');

        AmountHelper::toCents(10.0, '   ');
    }

    public function testFromCentsRejectsEmptyCurrency(): void
    {
        $this->expectException(InvalidCurrencyException::class);
        $this->expectExceptionMessage('currency must not be empty.');

        AmountHelper::fromCents(1000, '');
    }

    /**
     * @dataProvider invalidCurrencyProvider
     */
    public function testToCentsRejectsInvalidCurrency(string $currency): void
    {
        $this->expectException(InvalidCurrencyException::class);

        AmountHelper::toCents(10.0, $currency);
    }

    /**
     * @dataProvider invalidCurrencyProvider
     */
    public function testFromCentsRejectsInvalidCurrency(string $currency): void
    {
        $this->expectException(InvalidCurrencyException::class);

        AmountHelper::fromCents(1000, $currency);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function invalidCurrencyProvider(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'tab only' => ["\t"],
            'four letters' => ['EURO'],
            'two letters' => ['JP'],
            'two digits' => ['12'],
            'ISO numeric code' => ['978'],
            'digit inside' => ['E1R'],
            'embedded space' => ['J PY'],
            'non-ASCII symbol' => ['€UR'],
        ];
    }

    public function testMalformedCurrencyMessageNamesTheValue(): void
    {
        $this->expectException(InvalidCurrencyException::class);
        $this->expectExceptionMessage("currency must be a 3-letter ISO 4217 code, got 'EURO'.");

        AmountHelper::toCents(10.0, 'EURO');
    }
}
