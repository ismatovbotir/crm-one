<?php

namespace App\Enums;

/**
 * Sale type (Spec 06 section 25): B2B (default) sells against an invoice (proforma before payment,
 * tax document after delivery) and normally an agreement; retail sells against a sales receipt,
 * full payment before packaging, no agreement unless one is added.
 */
enum SaleType: string
{
    case B2b = 'b2b';
    case Retail = 'retail';

    public static function default(): self
    {
        return self::B2b;
    }

    public function isRetail(): bool
    {
        return $this === self::Retail;
    }

    public function label(): string
    {
        return __('orders.sale_types.'.$this->value);
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
