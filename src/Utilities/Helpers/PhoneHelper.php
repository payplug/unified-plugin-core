<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Utilities\Helpers;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use PayplugUnifiedCore\Exceptions\InvalidPhoneNumberException;

final class PhoneHelper
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Normalizes a customer-entered phone number (in whatever local format the
     * merchant's checkout form collected it) into E.164, the format required by
     * the Payplug API's phone number fields.
     *
     * Example:
     * <code>
     * $e164Phone = PhoneHelper::toE164($customer->getPhone(), 'FR'); // "06 12 34 56 78" => "+33612345678"
     * </code>
     *
     * @throws InvalidPhoneNumberException if $phone cannot be parsed, or is not a
     *     valid number for $countryCode
     */
    public static function toE164(string $phone, string $countryCode): string
    {
        $number = self::parse($phone, $countryCode);

        return PhoneNumberUtil::getInstance()->format($number, PhoneNumberFormat::E164);
    }

    /**
     * Determines whether a customer-entered phone number is a mobile line, so a
     * plugin can decide whether to offer mobile-only features (e.g. SMS order
     * notifications) for that customer.
     *
     * Example:
     * <code>
     * if (PhoneHelper::isMobile($customer->getPhone(), 'FR')) {
     *     // offer an SMS notification opt-in at checkout
     * }
     * </code>
     *
     * @throws InvalidPhoneNumberException if $phone cannot be parsed, or is not a
     *     valid number for $countryCode
     */
    public static function isMobile(string $phone, string $countryCode): bool
    {
        $type = PhoneNumberUtil::getInstance()->getNumberType(self::parse($phone, $countryCode));

        return PhoneNumberType::MOBILE === $type || PhoneNumberType::FIXED_LINE_OR_MOBILE === $type;
    }

    /**
     * @throws InvalidPhoneNumberException
     */
    private static function parse(string $phone, string $countryCode): PhoneNumber
    {
        $countryCode = strtoupper($countryCode);
        $phoneUtil = PhoneNumberUtil::getInstance();

        try {
            $number = $phoneUtil->parse($phone, $countryCode);
        } catch (NumberParseException $e) {
            throw new InvalidPhoneNumberException($e->getMessage(), 0, $e);
        }

        if (!$phoneUtil->isValidNumber($number) || $countryCode !== $phoneUtil->getRegionCodeForNumber($number)) {
            throw new InvalidPhoneNumberException(\sprintf(
                'Phone number is not a valid number for region "%s".',
                $countryCode
            ));
        }

        return $number;
    }
}
