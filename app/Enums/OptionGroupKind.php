<?php

namespace App\Enums;

/**
 * How a line takes part in an offer (Spec 05 DEC-1): fixed (no group, always included),
 * optional (opt-in add-on, never pre-selected) or alternative (choose among options).
 * Groups are only ever optional or alternative; fixed describes lines without a group.
 */
enum OptionGroupKind: string
{
    case Fixed = 'fixed';
    case Optional = 'optional';
    case Alternative = 'alternative';

    public function label(): string
    {
        return __('offers.kinds.'.$this->value);
    }

    public function isGroup(): bool
    {
        return $this !== self::Fixed;
    }

    /** Default min/max for a new group with $lines lines (Spec 05 7.3). */
    public function defaultMin(): int
    {
        return $this === self::Alternative ? 1 : 0;
    }

    public function defaultMax(int $lines): int
    {
        return $this === self::Alternative ? 1 : max(1, $lines);
    }
}
