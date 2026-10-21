<?php

namespace App\Services\Offers;

use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Services\Offers\Exceptions\OfferActionException;

/**
 * Hand-off contract for Spec 06 (Orders): what an order is created from when an offer was
 * accepted. Only fixed lines and the options the customer selected (from the frozen
 * `accepted_selection` snapshot) are included, each with its offer line id, group and label
 * ("3B"), the item snapshot and the line amounts copied verbatim, so order totals equal the
 * offer's selected totals to the minor unit (BR-8). Unselected options stay in offer history.
 */
class OfferToOrderPayload
{
    /**
     * @return array{
     *     offer: array<string, mixed>,
     *     lines: list<array<string, mixed>>,
     *     totals: array{net_minor: int, vat_minor: int, gross_minor: int, currency: string}
     * }
     */
    public function build(Offer $offer): array
    {
        if ($offer->status !== OfferStatus::Accepted) {
            throw new OfferActionException('not_accepted');
        }

        $offer->load(['lines', 'groups']);
        $accepted = collect($offer->accepted_selection ?? [])->flatMap(fn (array $group): array => array_column($group['lines'], 'stable_key'))->all();
        $lines = [];
        $totals = ['net_minor' => 0, 'vat_minor' => 0, 'gross_minor' => 0];

        foreach ($offer->blocks() as $block) {
            $candidates = $block['type'] === 'line' ? [$block['line']] : $block['lines']->filter(fn (OfferLine $line): bool => in_array($line->stable_key, $accepted, true))->all();

            foreach ($candidates as $line) {
                $lines[] = $this->line($line, $block);
                $totals['net_minor'] += $line->line_net_minor;
                $totals['vat_minor'] += $line->line_vat_minor;
                $totals['gross_minor'] += $line->line_gross_minor;
            }
        }

        return [
            'offer' => [
                'offer_id' => $offer->id,
                'number' => $offer->displayNumber(),
                'version' => $offer->version,
                'accepted_hash' => $offer->accepted_hash,
                'accepted_at' => $offer->accepted_at?->toIso8601String(),
                'lead_id' => $offer->lead_id,
                'customer_id' => $offer->customer_id,
                'contact_id' => $offer->contact_id,
                'owner_id' => $offer->owner_id,
                'currency' => $offer->currency,
                'prices_include_vat' => $offer->prices_include_vat,
                'payment_terms_days' => $offer->payment_terms_days,
                'payment_terms_text' => $offer->payment_terms_text,
                'delivery_terms_text' => $offer->delivery_terms_text,
                'warranty_text' => $offer->warranty_text,
            ],
            'lines' => $lines,
            'totals' => [...$totals, 'currency' => $offer->currency],
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    protected function line(OfferLine $line, array $block): array
    {
        return [
            'offer_line_id' => $line->id,
            'offer_option_group_id' => $line->option_group_id,
            'group_title' => $block['type'] === 'group' ? $block['group']->title : null,
            'option_label' => $block['number'].($line->option_label ?? ''),
            'item_id' => $line->item_id,
            'item_external_id' => $line->item_external_id,
            'sku' => $line->sku,
            'name' => $line->name,
            'accountant_code' => $line->accountant_code,
            'class_code' => $line->class_code,
            'package_code' => $line->package_code,
            'is_serialized' => $line->is_serialized,
            'image_path' => $line->image_path,
            'unit_id' => $line->unit_id,
            'unit_code' => $line->unit_code,
            'unit_name' => $line->unit_name,
            'quantity' => (string) $line->quantity,
            'unit_price_minor' => $line->unit_price_minor,
            'discount_bp' => $line->discount_bp,
            'tax_rate_bp' => $line->tax_rate_bp,
            'is_vat_exempt' => $line->is_vat_exempt,
            'cost_price_minor' => $line->cost_price_minor,
            'net_minor' => $line->line_net_minor,
            'vat_minor' => $line->line_vat_minor,
            'gross_minor' => $line->line_gross_minor,
            'lead_time_days' => $line->lead_time_days,
        ];
    }
}
