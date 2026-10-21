<?php

namespace App\Actions\Tenders;

use App\Enums\PurchaseOrderStatus;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Models\PurchaseOrder;
use App\Models\Tender;
use App\Models\TenderSource;
use App\Models\User;
use App\Services\Tenders\Exceptions\TenderActionException;
use Illuminate\Support\Facades\DB;

/**
 * "Create transport tender" on a confirmed PO (Spec 08 BR-PO.4/5/6, AC-1..AC-3): only when the
 * Incoterm leaves a leg to us; one active tender per PO; prefill of route, cargo (HS codes and
 * line totals), cargo value, Incoterm, readiness, pickup window, required delivery (earlier of
 * the PO date and the linked customer order promise − 2 days) and documents. The prefill map is
 * kept in `prefilled_from`; values stay editable. Created by a logistics user it is theirs;
 * raised by purchase it is an unowned request for logistics to take.
 */
class CreateTransportTenderFromPurchaseOrder
{
    public function __construct(protected ManageTender $tenders) {}

    public function handle(PurchaseOrder $po, User $user): Tender
    {
        if ($po->status !== PurchaseOrderStatus::Confirmed) {
            throw new TenderActionException('po_not_confirmed');
        }

        if (! $po->buyerHasTransportLeg()) {
            throw new TenderActionException('po_no_transport_leg', ['incoterm' => (string) $po->incoterm]);
        }

        return DB::transaction(function () use ($po, $user): Tender {
            PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            $active = TenderSource::query()->where('source_type', 'purchase_order')->where('source_id', $po->id)
                ->whereHas('tender', fn ($q) => $q->whereNotIn('status', [TenderStatus::Cancelled->value, TenderStatus::Completed->value]))
                ->first();

            if ($active !== null) {
                throw new TenderActionException('po_tender_exists', ['number' => (string) $active->tender?->number]);
            }

            $po->loadMissing(['lines', 'supplier', 'destinationWarehouse', 'order']);
            $isOwner = $user->can('create', [Tender::class, TenderType::Transport->value]);
            $tender = $this->tenders->create(TenderType::Transport, ['title' => __('tenders.po_tender_title', ['number' => $po->number, 'supplier' => (string) $po->supplier?->name])], $user, asRequest: ! $isOwner);

            $tender->sources()->create(['source_type' => 'purchase_order', 'source_id' => $po->id]);

            if ($po->order_id !== null) {
                $tender->sources()->firstOrCreate(['source_type' => 'order', 'source_id' => $po->order_id]);
            }

            $lot = $tender->lots()->first();
            $lot->transportSpec->forceFill($this->prefill($po))->save();

            return $tender->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function prefill(PurchaseOrder $po): array
    {
        $sum = fn (string $column): ?string => $po->lines->whereNotNull($column)->isEmpty() ? null : $po->lines->reduce(fn (string $carry, $line): string => bcadd($carry, (string) ($line->{$column} ?? '0'), 3), '0');
        $readiness = $po->readiness_date;
        $deliveryTo = $po->required_delivery_date;
        $promised = $po->order?->promised_delivery_on;

        if ($promised !== null) {
            $buffered = $promised->subDays(2);
            $deliveryTo = $deliveryTo === null || $buffered->lessThan($deliveryTo) ? $buffered : $deliveryTo;
        }

        $documents = array_values(array_intersect((array) $po->documents_available, config('tenders.vocab.documents')));
        $data = [
            'purchase_order_id' => $po->id,
            'origin_country' => $po->pickup_country,
            'origin_city' => $po->pickup_city,
            'origin_address' => $po->pickup_address,
            'destination_country' => 'UZ',
            'destination_city' => null,
            'destination_address' => $po->destinationWarehouse?->name,
            'cargo_description' => $po->lines->map(fn ($line): string => $line->name)->implode('; '),
            'hs_codes' => $po->lines->pluck('hs_code')->filter()->unique()->values()->all(),
            'gross_kg' => $sum('gross_weight_kg'),
            'net_kg' => $sum('net_weight_kg'),
            'volume_m3' => $sum('volume_m3'),
            'packages_count' => $po->lines->whereNotNull('packages_count')->isEmpty() ? null : (int) $po->lines->sum('packages_count'),
            'cargo_value_minor' => $po->subtotal_minor,
            'cargo_currency' => $po->currency,
            'incoterm' => $po->incoterm,
            'incoterm_place' => $po->incoterm_place,
            'readiness_date' => $readiness?->toDateString(),
            'pickup_from' => $readiness?->toDateString(),
            'pickup_to' => $readiness?->addDays(3)->toDateString(),
            'delivery_to' => $deliveryTo?->toDateString(),
            'required_documents' => $documents === [] ? null : $documents,
            'special_handling' => $po->special_handling,
        ];
        $data['prefilled_from'] = array_fill_keys(array_keys(array_filter($data, fn ($value): bool => $value !== null && $value !== [])), 'purchase_order');
        unset($data['prefilled_from']['purchase_order_id']);

        return $data;
    }

    /** Whether the user should see the warning "buyer must export-clear abroad" (EXW, BR-PO.4). */
    public static function exwWarning(PurchaseOrder $po): bool
    {
        return $po->incoterm === 'EXW';
    }
}
