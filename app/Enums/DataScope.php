<?php

namespace App\Enums;

/**
 * How much data a permission grant covers (Spec 03 section 4.9), from narrowest to widest:
 * own records < records linked to own work < own departments' records < everything.
 */
enum DataScope: string
{
    case Own = 'own';
    case Linked = 'linked';
    case Department = 'department';
    case All = 'all';

    public function rank(): int
    {
        return match ($this) {
            self::Own => 1,
            self::Linked => 2,
            self::Department => 3,
            self::All => 4,
        };
    }

    public function covers(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public static function widest(?self $a, ?self $b): ?self
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->rank() >= $b->rank() ? $a : $b;
    }

    public function label(): string
    {
        return __('departments.scopes.'.$this->value);
    }
}
