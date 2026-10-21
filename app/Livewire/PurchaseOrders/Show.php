<?php

namespace App\Livewire\PurchaseOrders;

use App\Actions\Tenders\CreateTransportTenderFromPurchaseOrder;
use App\Actions\Tenders\ManagePurchaseOrder;
use App\Enums\Incoterm;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Tender;
use App\Models\TenderSource;
use App\Models\Warehouse;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Purchase order card (Spec 08 12.1): header (Incoterm, pickup point, readiness, warehouse,
 * documents), cargo lines (weight source badges), confirm, "Create transport tender" when the
 * Incoterm leaves a leg to us, cancel with a reason, manual 1C number, print, status history.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    #[Locked]
    public int $poId;

    public string $onecNumber = '';

    public string $cancelReason = '';

    public bool $cancelling = false;

    /** @var array<string, mixed> */
    public array $header = [];

    public string $itemSearch = '';

    public ?int $catalogItemId = null;

    public ?int $editingLineId = null;

    /** @var array<string, mixed> */
    public array $line = [];

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        $this->authorize('view', $purchaseOrder);
        $this->poId = $purchaseOrder->id;
        $this->onecNumber = (string) $purchaseOrder->onec_number;
        $this->fillHeader();
        $this->resetLine();
    }

    #[Computed]
    public function po(): PurchaseOrder
    {
        return PurchaseOrder::query()->with(['supplier', 'owner', 'lines', 'award.tender', 'destinationWarehouse', 'transitions.user', 'order'])->findOrFail($this->poId);
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function itemOptions(): Collection
    {
        $term = trim($this->itemSearch);

        return $term === '' ? new Collection : Item::query()->where(fn ($q) => $q->where('sku', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%'))->orderBy('name')->limit(10)->get(['id', 'sku', 'name']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Tender>
     */
    #[Computed]
    public function tenders(): \Illuminate\Support\Collection
    {
        return TenderSource::query()->where('source_type', 'purchase_order')->where('source_id', $this->poId)->with('tender')->get()
            ->pluck('tender')->filter(fn ($tender): bool => $tender !== null && auth()->user()->can('view', $tender))->values();
    }

    public function saveHeader(ManagePurchaseOrder $manage): void
    {
        $this->authorize('edit', $this->po);
        $this->validate([
            'header.supplier_order_ref' => ['nullable', 'string', 'max:60'],
            'header.currency' => ['required', Rule::in(config('tenders.currencies'))],
            'header.incoterm' => ['nullable', Rule::enum(Incoterm::class)],
            'header.incoterm_place' => ['nullable', 'string', 'max:255'],
            'header.pickup_country' => ['nullable', 'alpha', 'size:2'],
            'header.pickup_city' => ['nullable', 'string', 'max:120'],
            'header.pickup_address' => ['nullable', 'string', 'max:1000'],
            'header.destination_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'header.readiness_date' => ['nullable', 'date'],
            'header.required_delivery_date' => ['nullable', 'date'],
            'header.payment_terms' => ['nullable', 'string', 'max:2000'],
            'header.documents_available' => ['array'],
            'header.documents_available.*' => [Rule::in(config('tenders.vocab.documents'))],
            'header.special_handling' => ['nullable', 'string', 'max:2000'],
        ]);

        $blank = fn ($value) => is_string($value) && trim($value) === '' ? null : $value;
        $this->run(fn () => $manage->update($this->po, [
            ...array_map($blank, $this->header),
            'pickup_country' => filled($this->header['pickup_country']) ? strtoupper((string) $this->header['pickup_country']) : null,
            'documents_available' => array_values((array) $this->header['documents_available']),
        ]), 'purchase_orders.flash.saved');
    }

    public function pickItem(int $itemId): void
    {
        $item = Item::query()->findOrFail($itemId);
        $this->catalogItemId = $item->id;
        $this->line['name'] = $item->name;
        $this->line['hs_code'] = (string) $item->hs_code;
        $this->itemSearch = '';
    }

    public function editLine(int $id): void
    {
        $row = PurchaseOrderLine::query()->where('purchase_order_id', $this->poId)->findOrFail($id);
        $this->editingLineId = $row->id;
        $this->catalogItemId = $row->item_id;
        $trim = fn ($value): string => $value === null ? '' : rtrim(rtrim((string) $value, '0'), '.');
        $this->line = [
            'name' => $row->name, 'unit_code' => (string) $row->unit_code, 'quantity' => $trim($row->quantity), 'unit_price' => Money::of($row->unit_price_minor, $this->po->currency)->toMajor(),
            'hs_code' => (string) $row->hs_code, 'country_of_origin' => (string) $row->country_of_origin, 'gross_weight_kg' => $trim($row->gross_weight_kg),
            'net_weight_kg' => $trim($row->net_weight_kg), 'volume_m3' => $trim($row->volume_m3), 'packages_count' => (string) ($row->packages_count ?? ''),
            'package_type' => (string) $row->package_type, 'weight_source' => $row->weight_source === 'manual' ? 'manual' : 'packing_list',
        ];
    }

    public function saveLine(ManagePurchaseOrder $manage): void
    {
        $this->authorize('edit', $this->po);
        $this->validate([
            'line.name' => ['required_without:catalogItemId', 'nullable', 'string', 'max:255'],
            'line.quantity' => ['required', 'string', 'max:20'],
            'line.unit_price' => ['required', 'string', 'max:30'],
            'line.hs_code' => ['nullable', 'regex:/^\d{6,10}$/'],
            'line.country_of_origin' => ['nullable', 'alpha', 'size:2'],
            'line.gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'line.net_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'line.volume_m3' => ['nullable', 'numeric', 'min:0'],
            'line.packages_count' => ['nullable', 'integer', 'min:0'],
            'line.package_type' => ['nullable', 'string', 'max:30'],
            'line.weight_source' => ['in:packing_list,manual'],
        ], [], ['line.name' => __('purchase_orders.fields.item'), 'line.quantity' => __('purchase_orders.fields.quantity'), 'line.unit_price' => __('purchase_orders.fields.unit_price')]);

        $row = $this->editingLineId !== null ? PurchaseOrderLine::query()->where('purchase_order_id', $this->poId)->findOrFail($this->editingLineId) : null;
        $saved = $this->run(fn () => $manage->saveLine($this->po, $row, [...$this->line, 'item_id' => $this->catalogItemId]), 'purchase_orders.flash.saved');

        if ($saved) {
            $this->resetLine();
        }
    }

    public function removeLine(ManagePurchaseOrder $manage, int $id): void
    {
        $this->authorize('edit', $this->po);
        $this->run(fn () => $manage->removeLine(PurchaseOrderLine::query()->where('purchase_order_id', $this->poId)->findOrFail($id)), null);
    }

    public function resetLine(): void
    {
        $this->catalogItemId = null;
        $this->editingLineId = null;
        $this->itemSearch = '';
        $this->line = ['name' => '', 'unit_code' => '', 'quantity' => '1', 'unit_price' => '', 'hs_code' => '', 'country_of_origin' => '', 'gross_weight_kg' => '', 'net_weight_kg' => '', 'volume_m3' => '', 'packages_count' => '', 'package_type' => '', 'weight_source' => 'packing_list'];
    }

    public function confirm(ManagePurchaseOrder $manage): void
    {
        $this->authorize('confirm', $this->po);
        $this->run(fn () => $manage->confirm($this->po, auth()->user()), 'purchase_orders.flash.confirmed');
    }

    public function createTransportTender(CreateTransportTenderFromPurchaseOrder $create): void
    {
        $this->authorize('createTransportTender', $this->po);

        try {
            $tender = $create->handle($this->po, auth()->user());
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('purchase_orders.flash.tender_created', ['number' => $tender->number]));
        $this->redirectRoute('tenders.show', ['tender' => $tender, 'tab' => 'lots'], navigate: true);
    }

    public function cancel(ManagePurchaseOrder $manage): void
    {
        $this->authorize('cancel', $this->po);
        $this->run(fn () => $manage->cancel($this->po, auth()->user(), $this->cancelReason), 'purchase_orders.flash.cancelled');
        $this->cancelling = false;
    }

    public function saveOnecNumber(ManagePurchaseOrder $manage): void
    {
        $this->authorize('update', $this->po);
        $this->run(fn () => $manage->setOnecNumber($this->po, $this->onecNumber), 'purchase_orders.flash.saved');
    }

    protected function run(callable $action, ?string $key): bool
    {
        try {
            $action();
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());
            unset($this->po);

            return false;
        }

        if ($key !== null) {
            session()->flash('status', __($key));
        }

        unset($this->po);
        $this->fillHeader();

        return true;
    }

    protected function fillHeader(): void
    {
        $po = $this->po;
        $this->header = [
            'supplier_order_ref' => (string) $po->supplier_order_ref, 'currency' => $po->currency, 'incoterm' => (string) $po->incoterm,
            'incoterm_place' => (string) $po->incoterm_place, 'pickup_country' => (string) $po->pickup_country, 'pickup_city' => (string) $po->pickup_city,
            'pickup_address' => (string) $po->pickup_address, 'destination_warehouse_id' => (string) ($po->destination_warehouse_id ?? ''),
            'readiness_date' => (string) $po->readiness_date?->toDateString(), 'required_delivery_date' => (string) $po->required_delivery_date?->toDateString(),
            'payment_terms' => (string) $po->payment_terms, 'documents_available' => (array) $po->documents_available, 'special_handling' => (string) $po->special_handling,
        ];
    }

    public function render(): View
    {
        return view('livewire.purchase-orders.show', [
            'incoterms' => Incoterm::options(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'exwWarning' => CreateTransportTenderFromPurchaseOrder::exwWarning($this->po),
        ])->title($this->po->number);
    }
}
