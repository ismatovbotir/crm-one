<?php

namespace App\Services\Orders;

use App\Enums\OrderAmendmentType;

/**
 * One-line description of a stored amendment change for the amendment screen ("5 → 8", "10 % → 15 %").
 */
final class OrderAmendmentSummary
{
    /**
     * @param  array<string, mixed>  $change
     */
    public static function describe(array $change): string
    {
        $percent = fn (int $bp): string => rtrim(rtrim(bcdiv((string) $bp, '100', 2), '0'), '.').' %';

        return match (OrderAmendmentType::tryFrom((string) ($change['type'] ?? ''))) {
            OrderAmendmentType::QtyChange => ($change['old_quantity'] ?? '?').' → '.($change['quantity'] ?? '?'),
            OrderAmendmentType::PriceChange => $percent((int) ($change['old_discount_bp'] ?? 0)).' → '.$percent((int) ($change['discount_bp'] ?? 0)),
            OrderAmendmentType::AddLine => ($change['quantity'] ?? '?').($change['discount_bp'] ?? 0 ? ' · −'.$percent((int) $change['discount_bp']) : ''),
            OrderAmendmentType::RemoveLine, OrderAmendmentType::CloseShort => '− '.($change['quantity'] ?? '?'),
            default => '',
        };
    }
}
