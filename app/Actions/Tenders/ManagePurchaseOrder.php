<?php

namespace App\Actions\Tenders;

use App\Enums\Incoterm;
use App\Enums\PurchaseOrderStatus;
use App\Models\Counterparty;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Services\Tenders\LandedCostCalculator;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Purchase orders (Spec 08 6.1, 10.1): manual POs to suppliers with Incoterm, pickup point,
 * readiness date and cargo lines (weights/volume prefilled from the item master × quantity,
 * editable from the supplier packing list); confirm (guards of 6.1), cancel with a reason,
 * the manual 1C document number (Phase 1; 1C is pull-only). Transit and receipt states come
 * with shipments and Spec 06 goods receipts. Totals are recomputed server-side.
 */
class ManagePurchaseOrder
{
    /** Header columns editable while the PO is a draft. */
    public const HEADER = [
        'supplier_id', 'supplier_order_ref', 'order_id', 'currency', 'incoterm', 'incoterm_place', 'pickup_country', 'pickup_city',
        'pickup_address', 'destination_warehouse_id', 'readiness_date', 'required_delivery_date', 'payment_terms', 'payment_days',
        'prepayment_bp', 'documents_available', 'special_handling',
    ];

    /**
     * @param  array<string, mixed>  $data  subset of HEADER
     */
    public function create(array $data, User $user): PurchaseOrder
    {
        $supplier = Counterparty::query()->find($data['supplier_id'] ?? null);

        if ($supplier === null || ! $supplier->is_supplier || ! $supplier->status->canBid()) {
            throw new TenderActionException('po_supplier_invalid');
        }

        return DB::transaction(function () use ($data, $user): PurchaseOrder {
            $year = (int) CarbonImmutable::now((string) config('tenders.timezone'))->year;
            $po = PurchaseOrder::query()->create([
                ...array_intersect_key($data, array_flip(self::HEADER)),
                'number' => NumberSequence::format('PO', $year, NumberSequence::next('purchase_order', $year)),
                'source' => 'manual',
                'owner_id' => $user->id,
                'department_id' => $user->activeDepartments()->first()?->id,
                'status' => PurchaseOrderStatus::Draft,
                'currency' => strtoupper((string) ($data['currency'] ?? 'USD')),
                'subtotal_minor' => 0,
            ]);
            $this->record($po, null, PurchaseOrderStatus::Draft, $user, null);

            return $po;
        });
    }

    /**
     * @param  array<string, mixed>  $data  subset of HEADER
     */
    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        $this->assertDraft($po);

        if (isset($data['incoterm']) && $data['incoterm'] !== null && Incoterm::tryFrom((string) $data['incoterm']) === null) {
            throw new TenderActionException('po_incoterm_invalid');
        }

        if (isset($data['currency']) && strtoupper((string) $data['currency']) !== $po->currency && $po->lines()->exists()) {
            throw new TenderActionException('po_currency_locked');
        }

        $po->update(array_intersect_key($data, array_flip(self::HEADER)));

        return $po;
    }

    /**
     * Add or update a line. Weights/volume default to item master × quantity (`weight_source =
     * master`); explicit values mark the line `packing_list` (supplier data) or `manual`.
     *
     * @param  array{item_id?: int|null, name?: string|null, unit_code?: string|null, quantity: string, unit_price: string, hs_code?: string|null, country_of_origin?: string|null, gross_weight_kg?: string|null, net_weight_kg?: string|null, volume_m3?: string|null, packages_count?: int|string|null, package_type?: string|null, weight_source?: string|null}  $data
     */
    public function saveLine(PurchaseOrder $po, ?PurchaseOrderLine $line, array $data): PurchaseOrderLine
    {
        $this->assertDraft($po);
        $quantity = Quantity::normalize((string) $data['quantity']);

        if (! Quantity::isDecimal($quantity) || bccomp($quantity, '0', 3) <= 0) {
            throw new TenderActionException('quantity_invalid');
        }

        try {
            $unitPrice = Money::fromMajor((string) $data['unit_price'], $po->currency)->amount;
        } catch (InvalidArgumentException) {
            throw new TenderActionException('amount_invalid', ['value' => (string) $data['unit_price']]);
        }

        if ($unitPrice < 0) {
            throw new TenderActionException('amount_invalid', ['value' => (string) $data['unit_price']]);
        }

        $item = isset($data['item_id']) ? Item::query()->with('unit')->find($data['item_id']) : null;
        $name = $item?->name ?? trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new TenderActionException('item_name_required');
        }

        $explicit = filled($data['gross_weight_kg'] ?? null) || filled($data['volume_m3'] ?? null);
        $master = fn (?string $perUnit): ?string => $perUnit === null ? null : bcmul($perUnit, $quantity, 3);
        $decimal = fn ($value): ?string => filled($value) ? Quantity::toStorage((string) $value) : null;

        $attributes = [
            'item_id' => $item?->id,
            'sku' => $item?->sku,
            'name' => $name,
            'unit_code' => $item?->unit?->code ?? ($data['unit_code'] ?? null),
            'unit_precision' => (int) ($item?->unit?->precision ?? 3),
            'quantity' => Quantity::toStorage($quantity),
            'unit_price_minor' => $unitPrice,
            'line_total_minor' => LandedCostCalculator::roundHalfUp(bcmul((string) $unitPrice, $quantity, 6)),
            'hs_code' => filled($data['hs_code'] ?? null) ? $data['hs_code'] : $item?->hs_code,
            'country_of_origin' => filled($data['country_of_origin'] ?? null) ? strtoupper((string) $data['country_of_origin']) : $item?->country_of_origin,
            'gross_weight_kg' => $explicit ? $decimal($data['gross_weight_kg'] ?? null) : $master($item?->gross_weight_kg !== null ? (string) $item->gross_weight_kg : null),
            'net_weight_kg' => $explicit ? $decimal($data['net_weight_kg'] ?? null) : $master($item?->net_weight_kg !== null ? (string) $item->net_weight_kg : null),
            'volume_m3' => $explicit ? $decimal($data['volume_m3'] ?? null) : $master($item?->volume_m3 !== null ? (string) $item->volume_m3 : null),
            'packages_count' => filled($data['packages_count'] ?? null) ? max(0, (int) $data['packages_count']) : null,
            'package_type' => $data['package_type'] ?? null,
            'weight_source' => $explicit ? (($data['weight_source'] ?? 'packing_list') === 'manual' ? 'manual' : 'packing_list') : 'master',
        ];

        return DB::transaction(function () use ($po, $line, $attributes): PurchaseOrderLine {
            if ($line === null) {
                $line = $po->lines()->create([...$attributes, 'position' => (int) $po->lines()->max('position') + 1]);
            } else {
                $line->update($attributes);
            }

            $this->recalculate($po);

            return $line;
        });
    }

    public function removeLine(PurchaseOrderLine $line): void
    {
        $po = PurchaseOrder::query()->findOrFail($line->purchase_order_id);
        $this->assertDraft($po);

        DB::transaction(function () use ($po, $line): void {
            $line->delete();
            $this->recalculate($po);
        });
    }

    /**
     * Spec 08 6.1: supplier, ≥ 1 line, Incoterm + named place, destination warehouse; when the
     * buyer has a transport leg also the pickup location and the readiness date.
     */
    public function confirm(PurchaseOrder $po, User $user): PurchaseOrder
    {
        if (! $po->supplier?->is_supplier || ! $po->supplier->status->canBid()) {
            throw new TenderActionException('po_supplier_invalid');
        }

        if (! $po->lines()->exists()) {
            throw new TenderActionException('po_no_lines');
        }

        if (blank($po->incoterm) || blank($po->incoterm_place) || $po->destination_warehouse_id === null) {
            throw new TenderActionException('po_terms_missing');
        }

        if ($po->buyerHasTransportLeg() && (blank($po->pickup_address) || blank($po->pickup_country) || $po->readiness_date === null)) {
            throw new TenderActionException('po_pickup_missing');
        }

        return $this->transition($po, PurchaseOrderStatus::Confirmed, $user, null, ['confirmed_at' => now(), 'confirmed_by' => $user->id]);
    }

    public function cancel(PurchaseOrder $po, User $user, string $reason): PurchaseOrder
    {
        if (trim($reason) === '') {
            throw new TenderActionException('reason_required');
        }

        return $this->transition($po, PurchaseOrderStatus::Cancelled, $user, trim($reason), ['cancelled_at' => now(), 'cancel_reason' => trim($reason)]);
    }

    public function setOnecNumber(PurchaseOrder $po, ?string $number): PurchaseOrder
    {
        $number = trim((string) $number);

        if (mb_strlen($number) > 30) {
            throw new TenderActionException('onec_number_invalid');
        }

        $po->update(['onec_number' => $number === '' ? null : $number]);

        return $po;
    }

    protected function recalculate(PurchaseOrder $po): void
    {
        $po->forceFill(['subtotal_minor' => (int) $po->lines()->sum('line_total_minor')])->save();
    }

    protected function assertDraft(PurchaseOrder $po): void
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            throw new TenderActionException('po_not_draft');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function transition(PurchaseOrder $po, PurchaseOrderStatus $to, User $user, ?string $reason, array $attributes): PurchaseOrder
    {
        $from = $po->status;

        if (! $from->canTransitionTo($to)) {
            throw new TenderActionException('invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
        }

        return DB::transaction(function () use ($po, $from, $to, $user, $reason, $attributes): PurchaseOrder {
            $po->forceFill([...$attributes, 'status' => $to])->save();
            $this->record($po, $from, $to, $user, $reason);

            return $po;
        });
    }

    protected function record(PurchaseOrder $po, ?PurchaseOrderStatus $from, PurchaseOrderStatus $to, User $user, ?string $reason): void
    {
        StatusTransition::query()->create([
            'subject_type' => $po->getMorphClass(),
            'subject_id' => $po->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $user->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
