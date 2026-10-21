<?php

namespace App\Enums;

/**
 * Approval levels of tender publishing and awards: department head (L1), CEO (head of management), finance co-approval above T2.
 */
enum ApprovalLevel: string
{
    case Head = 'head';
    case Ceo = 'ceo';
    case Finance = 'finance';

    public function label(): string
    {
        return __('tenders.levels.'.$this->value);
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
