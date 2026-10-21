<?php

namespace App\Enums;

/**
 * Soft stock reservation state (Spec 06 section 7.5, DEC-8).
 */
enum ReservationStatus: string
{
    case Active = 'active';
    case MirroredIn1c = 'mirrored_in_1c';
    case Consumed = 'consumed';
    case Released = 'released';

    public function label(): string
    {
        return __('orders.reservation_status.'.$this->value);
    }
}
