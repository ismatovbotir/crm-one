<?php

namespace App\Enums;

/**
 * Informational e-invoice (ESF) state, set manually in v1 (Spec 06 6.6, Q-2).
 */
enum EsfStatus: string
{
    case NotApplicable = 'not_applicable';
    case Pending = 'pending';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('accounting.esf_status.'.$this->value);
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
