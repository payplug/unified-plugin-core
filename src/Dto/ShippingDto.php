<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Dto;

/**
 * The payment's optional "shipping" block, per the Unified API's aliasing documentation
 * (advanced-payment-features/save-a-card/using-payplug-aliasing) — `BillingDto`'s sibling, same
 * structure: composes an `AddressDto` for its nested `address` sub-object, the same `ContactDto`
 * as `BillingDto` for its `firstName`/`lastName`/`phone`/`mobilePhone` fields, a
 * `ShippingScheduleDto` for its `addressType`/`timeFrame`/`addressDate` delivery-scheduling
 * fields, plus its own remaining sibling field (`companyName`; `email` is also shipping-only,
 * `BillingDto` has neither). Grouping the scheduling trio into its own DTO (rather than leaving
 * it flat, as an earlier version of this class did) is what keeps this constructor's parameter
 * count below SonarCloud's `php:S107` limit with real margin, instead of landing exactly on it.
 * Unlike `BrowserDto`/`CustomerDto`, none of these fields are required together, so every
 * constructor parameter defaults to `null` and `toArray()` omits whichever ones are still `null`
 * — same reasoning as `AddressDto`/`ContactDto`/`BillingDto`.
 */
final class ShippingDto
{
    /** @var AddressDto|null */
    public $address;

    /** @var ContactDto|null */
    public $contact;

    /** @var string|null */
    public $email;

    /** @var string|null */
    public $companyName;

    /** @var ShippingScheduleDto|null */
    public $schedule;

    public function __construct(
        ?AddressDto $address = null,
        ?ContactDto $contact = null,
        ?string $email = null,
        ?string $companyName = null,
        ?ShippingScheduleDto $schedule = null
    ) {
        $this->address = $address;
        $this->contact = $contact;
        $this->email = $email;
        $this->companyName = $companyName;
        $this->schedule = $schedule;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $shipping = [];

        if ($this->address !== null) {
            $shipping['address'] = $this->address->toArray();
        }

        if ($this->contact !== null) {
            // Flattened, not nested under a "contact" key — see ContactDto's own docblock.
            $shipping = array_merge($shipping, $this->contact->toArray());
        }

        if ($this->email !== null) {
            $shipping['email'] = $this->email;
        }

        if ($this->companyName !== null) {
            $shipping['companyName'] = $this->companyName;
        }

        if ($this->schedule !== null) {
            // Flattened, not nested under a "schedule" key — see ShippingScheduleDto's own docblock.
            $shipping = array_merge($shipping, $this->schedule->toArray());
        }

        return $shipping;
    }
}
