<?php

namespace App\Enums;

/**
 * State of an approval request (Spec 05 7.7).
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Returned = 'returned';
    case Invalidated = 'invalidated';

    public function label(): string
    {
        return __('offers.approval_status.'.$this->value);
    }
}
