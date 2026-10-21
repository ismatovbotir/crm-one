<?php

namespace App\Enums;

/**
 * Receipt (incoming) or refund (outgoing, against an incoming payment) (Spec 06 7.11).
 */
enum PaymentKind: string
{
    case Receipt = 'receipt';
    case Refund = 'refund';

    public function label(): string
    {
        return __('accounting.kinds.'.$this->value);
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
