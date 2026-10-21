<?php

namespace App\Enums;

/**
 * Order hold reasons (Spec 06 section 6.2, DEC-7): a flag set, not statuses.
 */
enum HoldReason: string
{
    case CreditLimit = 'credit_limit';
    case Overdue = 'overdue';
    case PrepaymentMissing = 'prepayment_missing';
    case AgreementUnsigned = 'agreement_unsigned';
    case Manual = 'manual';

    /** Holds that block confirmation (others block the start of fulfilment). */
    public function blocksConfirmation(): bool
    {
        return in_array($this, [self::CreditLimit, self::Overdue, self::Manual], true);
    }

    public function label(): string
    {
        return __('orders.holds.'.$this->value);
    }
}
