<?php

namespace App\Enums;

/**
 * Item kind (Spec 01 section 6.7): physical product or service.
 */
enum ItemType: string
{
    case Product = 'product';
    case Service = 'service';

    public function label(): string
    {
        return __('catalog.item_types.'.$this->value);
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
