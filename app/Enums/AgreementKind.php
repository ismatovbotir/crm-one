<?php

namespace App\Enums;

/**
 * Agreement kinds (Spec 06 DEC-9). Templates also have `termination` (letters, later).
 */
enum AgreementKind: string
{
    case Order = 'order';
    case Framework = 'framework';
    case Addendum = 'addendum';

    public function label(): string
    {
        return __('agreements.kinds.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
