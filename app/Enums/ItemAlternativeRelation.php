<?php

namespace App\Enums;

/**
 * Relation of a catalog analogue to an item (Spec 05 7.10). Mutual links store the inverse.
 */
enum ItemAlternativeRelation: string
{
    case Analogue = 'analogue';
    case Upgrade = 'upgrade';
    case Budget = 'budget';
    case Substitute = 'substitute';

    public function inverse(): self
    {
        return match ($this) {
            self::Upgrade => self::Budget,
            self::Budget => self::Upgrade,
            default => $this,
        };
    }

    public function label(): string
    {
        return __('offers.relations.'.$this->value);
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
