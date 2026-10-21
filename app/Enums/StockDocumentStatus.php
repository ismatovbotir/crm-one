<?php

namespace App\Enums;

/**
 * Goods receipt and delivery note states (Spec 06 21.4): draft → posted (immutable) or cancelled.
 */
enum StockDocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Draft => ['outline', null],
            self::Posted => ['dark', 'check'],
            self::Cancelled => ['outline', 'close'],
        };
    }

    public function label(): string
    {
        return __('stock.document_status.'.$this->value);
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
