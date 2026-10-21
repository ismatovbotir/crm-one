<?php

namespace App\Enums;

/**
 * Transport bid charge lines (Spec 08 7.1); demurrage is the separate mandatory rate field.
 */
enum ChargeCode: string
{
    case Freight = 'freight';
    case Fuel = 'fuel';
    case BorderPermits = 'border_permits';
    case TerminalStorage = 'terminal_storage';
    case CustomsBrokerage = 'customs_brokerage';
    case Insurance = 'insurance';
    case Loading = 'loading';
    case Unloading = 'unloading';
    case Documents = 'documents';
    case EscortPermitsSpecial = 'escort_permits_special';
    case Other = 'other';

    public function label(): string
    {
        return __('tenders.charges.'.$this->value);
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
