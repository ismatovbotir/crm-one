<?php

namespace App\Enums;

/**
 * How the goods of an order reach the customer (Spec 06 section 31): delivered, or collected by
 * the customer (self pick-up with a handover act recording who collected and their document).
 */
enum DeliveryMethod: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';

    public function label(): string
    {
        return __('orders.delivery_methods.'.$this->value);
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
