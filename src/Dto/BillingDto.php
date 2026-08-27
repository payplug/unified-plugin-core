<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Dto;

/**
 * The payment's optional "billing" block, per the Unified API's aliasing documentation
 * (advanced-payment-features/save-a-card/using-payplug-aliasing) — composes an `AddressDto` for
 * its nested `address` sub-object, a `ContactDto` for its `firstName`/`lastName`/`phone`/
 * `mobilePhone` fields (shared with `ShippingDto`'s sibling shape — see `ContactDto`), plus its
 * own remaining sibling field (`title`). Unlike `BrowserDto`/`CustomerDto`, none of these fields
 * are required together, so every constructor parameter defaults to `null` and `toArray()` omits
 * whichever ones are still `null` — same reasoning as `AddressDto`/`ContactDto`.
 *
 * This class's own constructor never grew large enough to trip SonarCloud's `php:S107` (it had
 * 6 parameters before composing `ContactDto`, well under the limit that forced the extraction on
 * `ShippingDto`) — it composes `ContactDto` anyway, deliberately, so the two sibling blocks share
 * one shape for their one truly identical field group instead of each hand-rolling it.
 */
final class BillingDto
{
    /** @var AddressDto|null */
    public $address;

    /** @var ContactDto|null */
    public $contact;

    /** @var string|null "MR", "MRS", or "MISS" */
    public $title;

    public function __construct(
        ?AddressDto $address = null,
        ?ContactDto $contact = null,
        ?string $title = null
    ) {
        $this->address = $address;
        $this->contact = $contact;
        $this->title = $title;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $billing = [];

        if ($this->address !== null) {
            $billing['address'] = $this->address->toArray();
        }

        if ($this->contact !== null) {
            // Flattened, not nested under a "contact" key — see ContactDto's own docblock.
            $billing = array_merge($billing, $this->contact->toArray());
        }

        if ($this->title !== null) {
            $billing['title'] = $this->title;
        }

        return $billing;
    }
}
