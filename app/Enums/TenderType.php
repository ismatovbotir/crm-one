<?php

namespace App\Enums;

/**
 * Tender types sharing one engine (Spec 03 DEC-11): purchase of goods/services and transport.
 */
enum TenderType: string
{
    case Purchase = 'purchase';
    case Transport = 'transport';

    /** Number prefix (BR-T.1): PT-YYYY-NNNNN / TT-YYYY-NNNNN. */
    public function prefix(): string
    {
        return $this === self::Purchase ? 'PT' : 'TT';
    }

    /** Owning department code (Spec 03 4.9). */
    public function departmentCode(): string
    {
        return $this === self::Purchase ? 'purchase' : 'logistics';
    }

    /** Route segment: tenders.purchase.* / tenders.transport.*. */
    public function routeKey(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return __('tenders.type.'.$this->value);
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
