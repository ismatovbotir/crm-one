<?php

namespace App\Enums;

/**
 * 1C outbox event state (Spec 06 7.15/9.2).
 */
enum OutboxStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Acked = 'acked';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
    case BlockedCustomer = 'blocked_customer';

    /** Open = waiting for 1C (returned by GET /outbox/pending). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Delivered], true);
    }

    public function label(): string
    {
        return __('accounting.outbox_status.'.$this->value);
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
