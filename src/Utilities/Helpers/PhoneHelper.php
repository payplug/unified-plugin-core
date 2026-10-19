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
     * $e164Phone = PhoneHelper::toE164($customer->getPhone(), 'DE', false); // "+33 6 12 34 56 78" => "+33612345678"
     * </code>
     *
     * @param string $countryCode        ISO 3166-1 alpha-2 country of the customer's address, used as
     *                                   the default region for numbers entered in national format
     * @param bool   $requireRegionMatch true: the number must be valid AND belong to $countryCode.
     *                                   false: any valid number is accepted and formatted in its own
     *                                   country; keep true when the payment method requires the phone
     *                                   to belong to the address country. With false, a national-format
     *                                   number is always read in $countryCode ("0612345678" on a DE
     *                                   address is a DE fixed line), and a "00" prefix is only read as
     *                                   international when it is $countryCode's international prefix
     *
     * @throws InvalidPhoneNumberException if $phone cannot be parsed, is not a valid
     *     number, does not belong to $countryCode while $requireRegionMatch is true, or
     *     if $countryCode is not a supported region while $requireRegionMatch is false
     */
    public static function toE164(string $phone, string $countryCode, bool $requireRegionMatch = true): string
    {
        $number = self::parse($phone, $countryCode, $requireRegionMatch);

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
     * PhoneHelper::isMobile('+33 6 12 34 56 78', 'DE', false); // true, line type of the FR number
     * </code>
     *
     * @param string $countryCode        same as toE164()
     * @param bool   $requireRegionMatch same as toE164(); with false, the line type is the one of
     *                                   the number's own country
     *
     * @throws InvalidPhoneNumberException in the same cases as toE164()
     */
    public static function isMobile(string $phone, string $countryCode, bool $requireRegionMatch = true): bool
    {
        $type = PhoneNumberUtil::getInstance()->getNumberType(self::parse($phone, $countryCode, $requireRegionMatch));

        return PhoneNumberType::MOBILE === $type || PhoneNumberType::FIXED_LINE_OR_MOBILE === $type;
    }

    /**
     * @throws InvalidPhoneNumberException
     */
    private static function parse(string $phone, string $countryCode, bool $requireRegionMatch): PhoneNumber
    {
        $countryCode = strtoupper($countryCode);
        $phoneUtil = PhoneNumberUtil::getInstance();

        if (!$requireRegionMatch && !\in_array($countryCode, $phoneUtil->getSupportedRegions(), true)) {
            throw new InvalidPhoneNumberException('Country code is not a supported region.');
        }

        try {
            $number = $phoneUtil->parse($phone, $countryCode);
        } catch (NumberParseException $e) {
            throw new InvalidPhoneNumberException($e->getMessage(), 0, $e);
        }

        if (!$requireRegionMatch) {
            if (!$phoneUtil->isValidNumber($number)) {
                throw new InvalidPhoneNumberException('Phone number is not a valid number.');
            }

            return $number;
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
