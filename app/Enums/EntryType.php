<?php

namespace App\Enums;

/**
 * Ledger entry types (Spec 06 7.13).
 */
enum EntryType: string
{
    case PaymentRecorded = 'payment_recorded';
    case PaymentApproved = 'payment_approved';
    case PaymentConfirmed = 'payment_confirmed';
    case PaymentVoided = 'payment_voided';
    case RefundRecorded = 'refund_recorded';
    case Allocation = 'allocation';
    case Deallocation = 'deallocation';
    case InvoiceIssued = 'invoice_issued';
    case InvoiceVoided = 'invoice_voided';
    case OnecCorrection = 'onec_correction';

    public function label(): string
    {
        return __('accounting.entry_types.'.$this->value);
    }
}
