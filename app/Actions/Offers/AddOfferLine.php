<?php

namespace App\Actions\Offers;

use App\Enums\OptionGroupKind;
use App\Enums\StockBand;
use App\Models\Item;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\OfferOptionGroup;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\Offers\OfferValidator;
use App\Services\Pricing\Exceptions\ItemNotSellable;
use App\Services\Pricing\Exceptions\PriceNotFound;
use App\Services\Pricing\ItemPricing;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds a catalog item as a fixed line or as an option of a group (Spec 05 US-2/US-4). The price
 * is the item's single sale price (rev. 4), converted once to the offer's VAT basis, snapshotted
 * with the item fields (code, name, unit, VAT, accounting codes, serial flag), the stock band and
 * the cost basis (stock cost price, else purchase price) used for margin and the below-cost check.
 */
class AddOfferLine
{
    public function __construct(
        protected BeginOfferEdit $begin,
        protected RecalculateOffer $recalculate,
        protected ItemPricing $prices,
    ) {}

    public function handle(Offer $offer, Item $item, string $quantity, User $user, ?OfferOptionGroup $group = null): OfferLine
    {
        $this->begin->handle($offer, $user);
        $quantity = Quantity::normalize($quantity);

        if ($group !== null && $group->offer_id !== $offer->id) {
            throw new OfferActionException('group_mismatch');
        }

        $item->loadMissing(['unit', 'stocks']);

        if (! $item->isSellable()) {
            throw new OfferActionException('item_not_sellable', ['item' => $item->name]);
        }

        $quantityError = OfferValidator::quantityError($quantity, $item->unit->precision, $item->is_serialized, $item->min_order_qty !== null ? (string) $item->min_order_qty : null, $item->order_step !== null ? (string) $item->order_step : null);

        if ($quantityError !== null) {
            throw new OfferActionException($quantityError, ['line' => $item->name, 'precision' => $item->unit->precision, 'min' => (string) $item->min_order_qty, 'step' => (string) $item->order_step]);
        }

        if ($group !== null) {
            $max = (int) config('offers.max_options_per_group', 6);

            if ($group->kind === OptionGroupKind::Alternative && $group->lines()->count() >= $max) {
                throw new OfferActionException('too_many_options', ['max' => $max]);
            }

            if ($group->lines()->where('item_id', $item->id)->exists()) {
                throw new OfferActionException('duplicate_item');
            }
        }

        try {
            $salePrice = $this->prices->salePrice($item, $offer->currency);
        } catch (ItemNotSellable) {
            throw new OfferActionException('item_not_sellable', ['item' => $item->name]);
        } catch (PriceNotFound) {
            throw new OfferActionException('no_price', ['currency' => $offer->currency]);
        }

        return DB::transaction(function () use ($offer, $item, $quantity, $group, $salePrice): OfferLine {
            $taxRate = $item->is_vat_exempt ? 0 : $item->tax_rate_bp;
            $unitPrice = ItemPricing::toBasis($salePrice->amount, $item->prices_include_vat, $offer->prices_include_vat, $taxRate);
            $groupLineCount = $group?->lines()->count() ?? 0;

            $line = OfferLine::query()->create([
                'offer_id' => $offer->id,
                'option_group_id' => $group?->id,
                'stable_key' => (string) Str::uuid(),
                'position' => $group !== null ? ((int) $group->lines()->max('position')) + 1 : MoveOfferBlock::nextBlockPosition($offer),
                'item_id' => $item->id,
                'item_external_id' => $item->external_id,
                'sku' => $item->sku,
                'article' => $item->article,
                'accountant_code' => $item->accountant_code,
                'class_code' => $item->class_code,
                'package_code' => $item->package_code,
                'is_serialized' => $item->is_serialized,
                'item_image_id' => $item->mainImage?->id,
                'name' => $item->name,
                'description' => $item->description,
                'unit_id' => $item->unit_id,
                'unit_code' => $item->unit->code,
                'unit_name' => $item->unit->name,
                'unit_precision' => $item->unit->precision,
                'tax_rate_bp' => $item->is_vat_exempt ? 0 : $item->tax_rate_bp,
                'is_vat_exempt' => $item->is_vat_exempt,
                'price_source' => ItemPricing::SOURCE_ITEM,
                'list_unit_price_minor' => $unitPrice,
                'cost_price_minor' => $this->prices->costBasis($item, $offer->currency),
                'quantity' => Quantity::toStorage($quantity),
                'unit_price_minor' => $unitPrice,
                'discount_bp' => 0,
                'stock_band' => StockBand::for($item, $quantity),
                'stock_available_qty' => $item->stocks->isEmpty() ? null : $item->availableStock(),
                'stock_checked_at' => now(),
            ]);

            // Optional groups default to "any number of add-ons": keep max = number of lines.
            if ($group !== null && $group->kind === OptionGroupKind::Optional && $group->max_select >= $groupLineCount) {
                $group->forceFill(['max_select' => $groupLineCount + 1])->save();
            }

            $offer->logActivity('line_added', ['sku' => $item->sku, 'name' => $item->name, 'group' => $group?->title]);
            $this->recalculate->handle($offer);

            return $line->refresh();
        });
    }
}
