<?php

namespace App\Services\Offers;

use App\Models\Offer;
use App\Models\OfferLine;

/**
 * Canonical customer-visible content of an offer version (Spec 05 7.8): everything the customer
 * sees, no internal fields (internal notes, internal_note, stock quantities, list prices).
 */
class OfferSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Offer $offer): array
    {
        $offer->loadMissing(['lines', 'groups', 'customer', 'contact', 'owner']);

        return [
            'number' => $offer->number,
            'version' => $offer->version,
            'currency' => $offer->currency,
            'prices_include_vat' => $offer->prices_include_vat,
            'valid_until' => $offer->valid_until->toDateString(),
            'customer' => ['id' => $offer->customer_id, 'name' => $offer->customer?->legal_name ?? $offer->customer?->name, 'tax_id' => $offer->customer?->tax_id],
            'contact' => $offer->contact?->fullName(),
            'terms' => [
                'payment_terms_days' => $offer->payment_terms_days,
                'payment' => $offer->payment_terms_text,
                'delivery' => $offer->delivery_terms_text,
                'warranty' => $offer->warranty_text,
            ],
            'intro' => $offer->intro_text,
            'notes' => $offer->customer_notes,
            'blocks' => $offer->blocks()->map(fn (array $block): array => $block['type'] === 'line'
                ? ['number' => $block['number'], 'type' => 'line', 'line' => $this->line($block['line'])]
                : [
                    'number' => $block['number'], 'type' => 'group', 'kind' => $block['group']->kind->value,
                    'stable_key' => $block['group']->stable_key, 'title' => $block['group']->title, 'description' => $block['group']->description,
                    'min_select' => $block['group']->min_select, 'max_select' => $block['group']->max_select,
                    'lines' => $block['lines']->map(fn (OfferLine $line): array => $this->line($line))->all(),
                ])->all(),
            'totals' => [
                'fixed_gross_minor' => $offer->fixed_gross_minor,
                'range_min_gross_minor' => $offer->range_min_gross_minor,
                'range_max_gross_minor' => $offer->range_max_gross_minor,
                'recommended_gross_minor' => $offer->recommended_gross_minor,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(OfferLine $line): array
    {
        return [
            'stable_key' => $line->stable_key,
            'label' => $line->option_label,
            'is_recommended' => $line->is_recommended,
            'sku' => $line->sku,
            'name' => $line->name,
            'unit' => $line->unit_name,
            'quantity' => (string) $line->quantity,
            'unit_price_minor' => $line->unit_price_minor,
            'discount_bp' => $line->discount_bp,
            'tax_rate_bp' => $line->tax_rate_bp,
            'net_minor' => $line->line_net_minor,
            'vat_minor' => $line->line_vat_minor,
            'gross_minor' => $line->line_gross_minor,
            'lead_time_days' => $line->lead_time_days,
            'stock_band' => $line->stock_band?->value,
            'reason' => $line->alternative_reason?->value,
            'reason_note' => $line->alternative_reason_note,
            'comparison_notes' => $line->comparison_notes,
        ];
    }
}
