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
}
