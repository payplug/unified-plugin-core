<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Dto;

use PayplugUnifiedCore\Traits\OmitsNullPropertiesFromArray;

/**
 * `ShippingDto`'s "delivery scheduling" fields (`addressType`, `timeFrame`, `addressDate`) — a
 * durable domain grouping, not just a parameter-count workaround: these three describe when/how
 * a shipment is delivered, distinct from the shipping contact/address fields. Grouping them here
 * (rather than leaving them flat on `ShippingDto`) is what gives `ShippingDto`'s own constructor
 * real headroom below SonarCloud's `php:S107` limit, instead of landing exactly on it the way the
 * `ContactDto` extraction alone did. Composed flat into `ShippingDto`, same as `ContactDto` — the
 * Unified API has no `schedule` sub-object; `addressType`/`timeFrame`/`addressDate` are flat
 * sibling fields of `shipping` on the wire. Unlike `BrowserDto`/`CustomerDto`, none of these
 * fields are required together, so every constructor parameter defaults to `null` and `toArray()`
 * omits whichever ones are still `null` — same reasoning as `AddressDto`/`ContactDto`.
 */
final class ShippingScheduleDto
{
    use OmitsNullPropertiesFromArray;

    /** @var string|null */
    public $addressType;

    /** @var string|null */
    public $timeFrame;

    /** @var string|null */
    public $addressDate;

    public function __construct(
        ?string $addressType = null,
        ?string $timeFrame = null,
        ?string $addressDate = null
    ) {
        $this->addressType = $addressType;
        $this->timeFrame = $timeFrame;
        $this->addressDate = $addressDate;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->toArrayOmittingNulls();
    }
}
