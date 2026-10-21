<?php

namespace App\Enums;

/**
 * Kind of a logged lead activity (Spec 04 US-2).
 */
enum LeadActivityType: string
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case Note = 'note';

    public function label(): string
    {
        return __('leads.activity_types.'.$this->value);
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
