<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Traits;

/**
 * Shared `toArray()` body for small value objects whose only fields are plain public scalar
 * properties, each keyed by its own property name with no renaming or nesting — the identical
 * "if ($this->x !== null) { $arr['x'] = $this->x; }" block was independently hand-written by
 * `AddressDto`/`ContactDto` before being factored out here. `get_object_vars($this)`, called from
 * within the using class's own scope, returns exactly that class's declared public properties in
 * declaration order, matching what each of those classes' constructors already assign in order —
 * so this doesn't change any existing `toArray()` output, key order included. Not usable by
 * `BuildsCommonPayloadBody`'s trait: that one maps to renamed/nested keys and splices in
 * payment-method-specific fields, so a straight property dump doesn't apply there.
 */
trait OmitsNullPropertiesFromArray
{
    /**
     * @return array<string, mixed>
     */
    private function toArrayOmittingNulls(): array
    {
        return array_filter(get_object_vars($this), function ($value): bool {
            return $value !== null;
        });
    }
}
