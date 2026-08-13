<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Dto;

use PayplugUnifiedCore\Traits\OmitsNullPropertiesFromArray;

/**
 * The `firstName`/`lastName`/`phone`/`mobilePhone` fields shared identically by `BillingDto` and
 * `ShippingDto` (their only overlapping sibling fields — billing additionally has `title`,
 * shipping additionally has `email`/`companyName`/`addressType`/`timeFrame`/`addressDate`).
 * Factored out once `ShippingDto`'s constructor grew to 10 parameters and tripped SonarCloud's
 * `php:S107` ("too many parameters") check. Unlike `BrowserDto`/`CustomerDto`, none of these
 * fields are required together, so every constructor parameter defaults to `null` and
 * `toArray()` omits whichever ones are still `null` — same reasoning as `AddressDto`.
 *
 * Composed flat: unlike `AddressDto` (nested under an `"address"` key by `BillingDto`/
 * `ShippingDto`), this DTO's `toArray()` result is merged directly into the composing class's
 * own array — the Unified API has no `contact` sub-object; `firstName`/`lastName`/`phone`/
 * `mobilePhone` are flat sibling fields of `billing`/`shipping` on the wire. Don't nest this
 * under a `"contact"` key by analogy with `AddressDto` — that would send a shape the API
 * doesn't expect.
 *
 * Distinct from `CustomerDto` (`id`/`email`, both required together): `CustomerDto` identifies
 * the payer for the risk/3DS context sent alongside every payment (`customer`, at the body's top
 * level), while `ContactDto` is billing/shipping contact detail nested only inside those two
 * optional blocks. A future payment method needing "who is paying" should compose `CustomerDto`,
 * not this one.
 */
final class ContactDto
{
    use OmitsNullPropertiesFromArray;

    /** @var string|null */
    public $firstName;

    /** @var string|null */
    public $lastName;

    /** @var string|null */
    public $phone;

    /** @var string|null */
    public $mobilePhone;

    public function __construct(
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $phone = null,
        ?string $mobilePhone = null
    ) {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->phone = $phone;
        $this->mobilePhone = $mobilePhone;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->toArrayOmittingNulls();
    }
}
