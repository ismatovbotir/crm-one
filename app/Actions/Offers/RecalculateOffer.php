<?php

namespace App\Actions\Offers;

use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\OfferOptionGroup;
use App\Services\Offers\Calculation\CalculatorGroup;
use App\Services\Offers\Calculation\CalculatorLine;
use App\Services\Offers\Calculation\OfferCalculation;
use App\Services\Offers\OfferCalculator;

/**
 * Recomputes every line amount and the offer totals with OfferCalculator and persists them,
 * together with the content hash (Spec 05 8, AC-9: client-side totals are never trusted).
 * Option letters A..F follow the order inside each group while the offer is editable and are
 * frozen once sent (customers refer to "3B").
 */
class RecalculateOffer
{
    public function __construct(protected OfferCalculator $calculator) {}

    public function handle(Offer $offer): OfferCalculation
    {
        $offer->load(['lines', 'groups']);

        if ($offer->status->isEditable()) {
            $this->assignLabels($offer);
        }

        $selected = $offer->selectedLineIds();
        $calculation = $this->calculator->calculate(
            $offer->lines->map(fn (OfferLine $line): CalculatorLine => new CalculatorLine(
                key: $line->id,
                quantity: (string) $line->quantity,
                unitPriceMinor: $line->unit_price_minor,
                discountBp: $line->discount_bp,
                taxRateBp: $line->tax_rate_bp,
                groupKey: $line->option_group_id,
                isRecommended: $line->is_recommended,
                listUnitPriceMinor: $line->list_unit_price_minor,
            ))->all(),
            $offer->groups->map(fn (OfferOptionGroup $group): CalculatorGroup => new CalculatorGroup($group->id, $group->kind, $group->min_select, $group->max_select))->all(),
            $offer->prices_include_vat,
            $selected,
        );

        foreach ($offer->lines as $line) {
            $amounts = $calculation->lines[$line->id];
            $line->forceFill([
                'line_discount_minor' => $amounts->discount,
                'line_net_minor' => $amounts->net,
                'line_vat_minor' => $amounts->vat,
                'line_gross_minor' => $amounts->gross,
                'effective_discount_bp' => $amounts->effectiveDiscountBp,
            ]);

            if ($line->isDirty()) {
                $line->save();
            }
        }

        $offer->forceFill([
            'fixed_net_minor' => $calculation->fixedNet,
            'fixed_vat_minor' => $calculation->fixedVat,
            'fixed_gross_minor' => $calculation->fixedGross,
            'selected_net_minor' => $calculation->selectedNet,
            'selected_vat_minor' => $calculation->selectedVat,
            'selected_gross_minor' => $calculation->selectedGross,
            'range_min_gross_minor' => $calculation->rangeMinGross,
            'range_max_gross_minor' => $calculation->rangeMaxGross,
            'recommended_gross_minor' => $calculation->recommendedGross,
            'pipeline_value_minor' => $calculation->pipelineValue(),
            'max_discount_bp' => $calculation->maxDiscountBp,
            'selection_complete' => $calculation->selectionComplete,
            'content_hash' => self::contentHash($offer),
        ])->save();

        return $calculation;
    }

    /**
     * sha256 of the customer-relevant content (header + groups + lines, excluding selections).
     * Approval is bound to it (DEC-6): any content change invalidates an approval.
     */
    public static function contentHash(Offer $offer): string
    {
        $offer->loadMissing(['lines', 'groups']);

        return hash('sha256', (string) json_encode([
            'header' => [
                $offer->customer_id, $offer->contact_id, $offer->currency, $offer->prices_include_vat, $offer->valid_until?->toDateString(),
                $offer->payment_terms_days, $offer->payment_terms_text, $offer->delivery_terms_text, $offer->warranty_text,
                $offer->intro_text, $offer->customer_notes,
            ],
            'groups' => $offer->groups->map(fn (OfferOptionGroup $group): array => [
                $group->stable_key, $group->position, $group->kind->value, $group->title, $group->description, $group->min_select, $group->max_select,
            ])->all(),
            'lines' => $offer->lines->map(fn (OfferLine $line): array => [
                $line->stable_key, $line->option_group_id === null ? null : $offer->groups->firstWhere('id', $line->option_group_id)?->stable_key,
                $line->position, $line->item_id, (string) $line->quantity, $line->unit_price_minor, $line->discount_bp, $line->tax_rate_bp,
                $line->is_recommended, $line->alternative_reason?->value, $line->alternative_reason_note, $line->comparison_notes, $line->lead_time_days,
            ])->all(),
        ]));
    }

    protected function assignLabels(Offer $offer): void
    {
        foreach ($offer->groups as $group) {
            $letters = range('A', 'Z');

            foreach ($offer->lines->where('option_group_id', $group->id)->sortBy([['position', 'asc'], ['id', 'asc']])->values() as $index => $line) {
                $line->option_label = $letters[$index] ?? null;
            }
        }

        foreach ($offer->lines->whereNull('option_group_id') as $line) {
            $line->option_label = null;
        }
    }
}
