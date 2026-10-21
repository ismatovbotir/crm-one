<?php

namespace App\Enums;

/**
 * Where an equipment record came from (Spec 09 §4): our sale (automatic, verified), the customer
 * (unverified until staff confirm) or staff.
 */
enum AssetSource: string
{
    case Sale = 'sale';
    case CustomerRegistered = 'customer_registered';
    case StaffRegistered = 'staff_registered';

    public function label(): string
    {
        return __('service.asset_sources.'.$this->value);
    }
}
