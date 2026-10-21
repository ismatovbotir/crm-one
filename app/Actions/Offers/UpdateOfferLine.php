<?php

namespace App\Actions\Offers;

use App\Enums\AlternativeReason;
use App\Enums\OptionGroupKind;
use App\Enums\StockBand;
use App\Models\OfferLine;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\Offers\OfferValidator;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Changes a line of a draft: quantity, unit price (manual price vs the snapshotted list price is
 * counted as discount, 4.3), discount, lead time, recommended flag (one per alternative group),
 * reason, comparison notes, internal note. Amounts are recomputed server-side.
 */
class UpdateOfferLine
{
    public function __construct(protected BeginOfferEdit $begin, protected RecalculateOffer $recalculate) {}

    /**
     * @param  array{quantity?: string, unit_price_minor?: int, discount_bp?: int, lead_time_days?: int|null, is_recommended?: bool, alternative_reason?: string|null, alternative_reason_note?: string|null, comparison_notes?: string|null, internal_note?: string|null}  $data
     */
    public function handle(OfferLine $line, User $user, array $data): OfferLine
    {
        $offer = $line->offer;
        $this->begin->handle($offer, $user);

        if (array_key_exists('quantity', $data)) {
            $quantity = Quantity::normalize((string) $data['quantity']);
            $item = $line->item;
            $error = OfferValidator::quantityError($quantity, $line->unit_precision, $line->is_serialized, $item?->min_order_qty !== null ? (string) $item->min_order_qty : null, $item?->order_step !== null ? (string) $item->order_step : null);

            if ($error !== null) {
                throw new OfferActionException($error, ['line' => $line->name, 'precision' => $line->unit_precision, 'min' => (string) $item?->min_order_qty, 'step' => (string) $item?->order_step]);
            }

            $data['quantity'] = Quantity::toStorage($quantity);
        }

        if (isset($data['discount_bp']) && ($data['discount_bp'] < 0 || $data['discount_bp'] > 10000)) {
            throw new OfferActionException('discount_range');
        }

        if (isset($data['unit_price_minor']) && $data['unit_price_minor'] < 0) {
            throw new OfferActionException('price_negative');
        }

        if (isset($data['alternative_reason']) && $data['alternative_reason'] !== null && AlternativeReason::tryFrom($data['alternative_reason']) === null) {
            $data['alternative_reason'] = null;
        }

        return DB::transaction(function () use ($line, $data, $offer): OfferLine {
            $recommended = $data['is_recommended'] ?? null;
            unset($data['is_recommended']);

            $line->fill(array_intersect_key($data, array_flip([
                'quantity', 'unit_price_minor', 'discount_bp', 'lead_time_days', 'alternative_reason', 'alternative_reason_note',
                'comparison_notes', 'internal_note',
            ])));

            if ($recommended !== null && $line->group?->kind === OptionGroupKind::Alternative) {
                if ($recommended) {
                    OfferLine::query()->where('option_group_id', $line->option_group_id)->whereKeyNot($line->id)->update(['is_recommended' => false]);
                }

                $line->is_recommended = (bool) $recommended;
            }

            if ($line->isDirty('quantity') && $line->item !== null) {
                $line->stock_band = StockBand::for($line->item, (string) $line->quantity);
            }

            if ($line->isDirty()) {
                $changes = array_keys($line->getDirty());
                $line->save();
                $offer->logActivity('line_updated', ['sku' => $line->sku, 'fields' => implode(', ', $changes)]);
                $this->recalculate->handle($offer);
            }

            return $line->refresh();
        });
    }
}
