<?php

namespace App\Enums;

/**
 * Where an order came from (Spec 06 section 31): an accepted offer, or a manual order entered by
 * sales directly.
 */
enum OrderSource: string
{
    case Offer = 'offer';
    case Manual = 'manual';

    public function label(): string
    {
        return __('orders.sources.'.$this->value);
    }
}
