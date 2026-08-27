<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Dto;

use PayplugUnifiedCore\Traits\OmitsNullPropertiesFromArray;

/**
 * The address sub-object nested identically under a payment's optional "billing" and "shipping"
 * blocks (billing.address / shipping.address), per the Unified API's aliasing documentation
 * (advanced-payment-features/save-a-card/using-payplug-aliasing) — composed by both `BillingDto`
 * and `ShippingDto` (their `$address` property), which model the two parent blocks themselves,
 * each with their own additional sibling fields. Unlike BrowserDto/CustomerDto, every field here
 * is individually optional per the schema (no "all sub-fields present together or none" rule), so
 * every constructor parameter defaults to null and toArray() omits whichever ones are still null,
 * rather than forcing the caller to populate every field just to send one.
 */
final class AddressDto
{
    use OmitsNullPropertiesFromArray;

    /** @var string|null 1-50 chars */
    public $line;

    /** @var string|null 1-255 chars */
    public $city;

    /** @var string|null 2-letter ISO 3166-1 alpha-2 country code */
    public $country;

    /** @var string|null 0-3 chars */
    public $state;

    /** @var string|null 1-9 chars */
    public $zipCode;

    public function __construct(
        ?string $line = null,
        ?string $city = null,
        ?string $country = null,
        ?string $state = null,
        ?string $zipCode = null
    ) {
        $this->line = $line;
        $this->city = $city;
        $this->country = $country;
        $this->state = $state;
        $this->zipCode = $zipCode;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->toArrayOmittingNulls();
    }
}
