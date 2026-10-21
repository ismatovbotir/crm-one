<?php

namespace App\Enums;

/**
 * Lead priority (Spec 04 section 16): set at capture, shown in the list and the score.
 */
enum LeadPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return __('leads.priorities.'.$this->value);
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
