<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Utilities\Helpers;

use PayplugUnifiedCore\Exceptions\InvalidPhoneNumberException;
use PayplugUnifiedCore\Utilities\Helpers\PhoneHelper;
use PHPUnit\Framework\TestCase;

final class PhoneHelperTest extends TestCase
{
    /**
     * @dataProvider validMobileNumberProvider
     */
    public function testToE164NormalizesMobileNumber(string $phone, string $countryCode, string $expectedE164): void
    {
        self::assertSame($expectedE164, PhoneHelper::toE164($phone, $countryCode));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function validMobileNumberProvider(): array
    {
        return [
            'FR mobile, plain national format' => ['0612345678', 'FR', '+33612345678'],
            'FR mobile, dotted formatting noise' => ['06.12.34.56.78', 'FR', '+33612345678'],
            'BE mobile, spaced formatting noise' => ['0470 12 34 56', 'BE', '+32470123456'],
            'GB mobile, spaced formatting noise' => ['07400 123 456', 'GB', '+447400123456'],
            'IT mobile, spaced formatting noise' => ['312 345 6789', 'IT', '+393123456789'],
            'NL mobile, spaced formatting noise' => ['06 12345678', 'NL', '+31612345678'],
            'ES mobile, spaced formatting noise' => ['612 34 56 78', 'ES', '+34612345678'],
        ];
    }

    public function testToE164PreservesItalianFixedLineLeadingZero(): void
    {
        // Italy is the one country in this set where the leading 0 on a fixed-line
        // number is part of the national significant number itself (not a trunk
        // prefix stripped for international dialing), so E.164 keeps it.
        self::assertSame('+390212345678', PhoneHelper::toE164('02 1234 5678', 'IT'));
    }

    /**
     * @dataProvider mobileNumberProvider
     */
    public function testIsMobileReturnsTrueForMobileNumber(string $phone, string $countryCode): void
    {
        self::assertTrue(PhoneHelper::isMobile($phone, $countryCode));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function mobileNumberProvider(): array
    {
        return [
            'FR mobile' => ['0612345678', 'FR'],
            'BE mobile' => ['0470123456', 'BE'],
            'GB mobile' => ['07400123456', 'GB'],
            'IT mobile' => ['3123456789', 'IT'],
            'NL mobile' => ['0612345678', 'NL'],
            'ES mobile' => ['612345678', 'ES'],
        ];
    }

    /**
     * @dataProvider fixedLineNumberProvider
     */
    public function testIsMobileReturnsFalseForFixedLineNumber(string $phone, string $countryCode): void
    {
        self::assertFalse(PhoneHelper::isMobile($phone, $countryCode));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fixedLineNumberProvider(): array
    {
        return [
            'FR fixed line' => ['0123456789', 'FR'],
            'BE fixed line' => ['012345678', 'BE'],
            'GB fixed line' => ['01212345678', 'GB'],
            'IT fixed line' => ['0212345678', 'IT'],
            'NL fixed line' => ['0101234567', 'NL'],
            'ES fixed line' => ['810123456', 'ES'],
        ];
    }

    /**
     * @dataProvider invalidPhoneNumberProvider
     */
    public function testIsMobileThrowsOnInvalidNumber(string $phone, string $countryCode): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        PhoneHelper::isMobile($phone, $countryCode);
    }

    /**
     * @dataProvider invalidPhoneNumberProvider
     */
    public function testToE164ThrowsOnInvalidNumber(string $phone, string $countryCode): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        PhoneHelper::toE164($phone, $countryCode);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function invalidPhoneNumberProvider(): array
    {
        return [
            'garbage input' => ['not a phone number', 'FR'],
            'too short for any region' => ['123', 'FR'],
            'wrong pattern for region' => ['0000000000', 'FR'],
            'invalid country code' => ['0612345678', 'XX'],
            'valid number for a different region' => ['+14155552671', 'FR'],
        ];
    }

    public function testToE164AcceptsLowercaseCountryCode(): void
    {
        self::assertSame('+33612345678', PhoneHelper::toE164('0612345678', 'fr'));
    }

    public function testToE164ExceptionMessageDoesNotLeakPhoneNumber(): void
    {
        // '0000000000' parses structurally but fails isValidNumber() for FR, exercising
        // PhoneHelper's own sprintf() message (as opposed to the vendor library's).
        try {
            PhoneHelper::toE164('0000000000', 'FR');
            self::fail('Expected InvalidPhoneNumberException to be thrown.');
        } catch (InvalidPhoneNumberException $e) {
            self::assertStringNotContainsString('0000000000', $e->getMessage());
        }
    }

    /**
     * @dataProvider foreignNumberProvider
     */
    public function testToE164WithoutRegionMatchFormatsNumberInItsRealCountry(string $phone, string $countryCode, string $expectedE164): void
    {
        self::assertSame($expectedE164, PhoneHelper::toE164($phone, $countryCode, false));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function foreignNumberProvider(): array
    {
        return [
            'FR mobile in international format on a DE address' => ['+33612345678', 'DE', '+33612345678'],
            'FR mobile with 00 prefix on a BE address' => ['0033609860479', 'BE', '+33609860479'],
            'BE mobile with 00 prefix on a FR address' => ['0032 470 12 34 56', 'FR', '+32470123456'],
            'FR mobile with 00 prefix on a lowercase DE address' => ['0033612345678', 'de', '+33612345678'],
            'DE mobile in international format on a FR address' => ['+4915123456789', 'FR', '+4915123456789'],
        ];
    }

    public function testToE164WithoutRegionMatchFormatsOwnRegionNumberLikeStrictMode(): void
    {
        self::assertSame(PhoneHelper::toE164('0612345678', 'FR'), PhoneHelper::toE164('0612345678', 'FR', false));
    }

    /**
     * @dataProvider invalidEvenWithoutRegionMatchProvider
     */
    public function testToE164WithoutRegionMatchStillThrowsOnInvalidNumber(string $phone, string $countryCode): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        PhoneHelper::toE164($phone, $countryCode, false);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function invalidEvenWithoutRegionMatchProvider(): array
    {
        return [
            'invalid for the region' => ['234234234', 'IT'],
            'empty string' => ['', 'FR'],
            'garbage input' => ['not a phone number', 'FR'],
            'too short' => ['123', 'FR'],
            'unknown country code, national format' => ['0612345678', 'XX'],
            'unknown country code, international format' => ['+33612345678', 'XX'],
            'empty country code, international format' => ['+33612345678', ''],
            '00 prefix is not the international prefix of the default region' => ['0033609860479', 'US'],
        ];
    }

    /**
     * @dataProvider invalidNumberWithoutRegionMatchProvider
     */
    public function testToE164WithoutRegionMatchInvalidNumberMessageDoesNotMentionRegion(string $phone, string $countryCode): void
    {
        $this->expectException(InvalidPhoneNumberException::class);
        $this->expectExceptionMessageMatches('/^Phone number is not a valid number\.$/');

        PhoneHelper::toE164($phone, $countryCode, false);
    }

    /**
     * @dataProvider invalidNumberWithoutRegionMatchProvider
     */
    public function testIsMobileWithoutRegionMatchInvalidNumberMessageDoesNotMentionRegion(string $phone, string $countryCode): void
    {
        $this->expectException(InvalidPhoneNumberException::class);
        $this->expectExceptionMessageMatches('/^Phone number is not a valid number\.$/');

        PhoneHelper::isMobile($phone, $countryCode, false);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function invalidNumberWithoutRegionMatchProvider(): array
    {
        return [
            'invalid for the region' => ['234234234', 'IT'],
            'wrong pattern for the region' => ['0000000000', 'FR'],
            '00 prefix is not the international prefix of the default region' => ['0033609860479', 'US'],
        ];
    }

    public function testToE164WithRegionMatchInvalidNumberMessageNamesTheRegion(): void
    {
        $this->expectException(InvalidPhoneNumberException::class);
        $this->expectExceptionMessageMatches('/^Phone number is not a valid number for region "FR"\.$/');

        PhoneHelper::toE164('0000000000', 'fr');
    }

    /**
     * @dataProvider leakingCandidateProvider
     */
    public function testToE164WithoutRegionMatchExceptionMessageDoesNotLeakPhoneNumber(string $phone, string $countryCode, string $digits): void
    {
        try {
            PhoneHelper::toE164($phone, $countryCode, false);
            self::fail('Expected InvalidPhoneNumberException to be thrown.');
        } catch (InvalidPhoneNumberException $e) {
            self::assertStringNotContainsString($digits, $e->getMessage());
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function leakingCandidateProvider(): array
    {
        return [
            'unsupported country code' => ['+33612345678', 'XX', '33612345678'],
            'invalid for the region' => ['234234234', 'IT', '234234234'],
        ];
    }

    /**
     * @dataProvider foreignNumberProvider
     */
    public function testToE164WithRegionMatchExplicitlyRequiredRejectsForeignNumber(string $phone, string $countryCode): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        PhoneHelper::toE164($phone, $countryCode, true);
    }

    public function testToE164RequiresRegionMatchByDefault(): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        PhoneHelper::toE164('+33612345678', 'DE');
    }

    /**
     * @dataProvider foreignLineTypeProvider
     */
    public function testIsMobileWithoutRegionMatchUsesTheRealCountryOfTheNumber(string $phone, string $countryCode, bool $expected): void
    {
        self::assertSame($expected, PhoneHelper::isMobile($phone, $countryCode, false));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public function foreignLineTypeProvider(): array
    {
        return [
            'FR mobile on a DE address' => ['+33612345678', 'DE', true],
            'FR mobile with 00 prefix on a BE address' => ['0033609860479', 'BE', true],
            'BE mobile with 00 prefix on a FR address' => ['0032 470 12 34 56', 'FR', true],
            'GB fixed line on a FR address' => ['+442071838750', 'FR', false],
            'national format resolved in the default region as a DE fixed line' => ['0612345678', 'DE', false],
            'same national digits resolved in the default region as a MC mobile' => ['0612345678', 'MC', true],
        ];
    }

    /**
     * @dataProvider ownRegionLineTypeProvider
     */
    public function testIsMobileWithoutRegionMatchDetectsOwnRegionNumberLikeStrictMode(string $phone, string $countryCode, bool $expected): void
    {
        self::assertSame($expected, PhoneHelper::isMobile($phone, $countryCode, false));
        self::assertSame(PhoneHelper::isMobile($phone, $countryCode), PhoneHelper::isMobile($phone, $countryCode, false));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public function ownRegionLineTypeProvider(): array
    {
        return [
            'FR mobile on a FR address' => ['0612345678', 'FR', true],
            'FR fixed line on a FR address' => ['0123456789', 'FR', false],
        ];
    }

    /**
     * @dataProvider invalidEvenWithoutRegionMatchProvider
     */
    public function testIsMobileWithoutRegionMatchStillThrowsOnInvalidNumber(string $phone, string $countryCode): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        PhoneHelper::isMobile($phone, $countryCode, false);
    }

    public function testIsMobileRequiresRegionMatchByDefault(): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        PhoneHelper::isMobile('+33612345678', 'DE');
    }
}
