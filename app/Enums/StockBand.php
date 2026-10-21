<?php

namespace App\Enums;

use App\Models\Item;

/**
 * Customer-visible availability band of a line (Spec 05 7.4, Q-9): never quantities.
 */
enum StockBand: string
{
    case InStock = 'in_stock';
    case Limited = 'limited';
    case OnRequest = 'on_request';
    case OutOfStock = 'out_of_stock';

    /**
     * Band for a quantity of an item from the 1C stock mirror. Services have no band; items
     * without any stock rows are "on request".
     */
    public static function for(Item $item, string $quantity): ?self
    {
        if ($item->type === ItemType::Service) {
            return null;
        }

        $item->loadMissing('stocks');

        if ($item->stocks->isEmpty()) {
            return self::OnRequest;
        }

        $available = $item->availableStock();

        return match (true) {
            bccomp($available, $quantity, 3) >= 0 => self::InStock,
            bccomp($available, '0', 3) > 0 => self::Limited,
            default => self::OutOfStock,
        };
    }

    public function label(): string
    {
        return __('documents.offer.stock.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::InStock => 'check',
            self::Limited, self::OnRequest => 'clock',
            self::OutOfStock => 'warning',
        };
    }
}
