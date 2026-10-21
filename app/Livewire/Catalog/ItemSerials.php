<?php

namespace App\Livewire\Catalog;

use App\Actions\Stock\CorrectSerial;
use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\ReceiptSource;
use App\Enums\SerialStatus;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockActionException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Serial numbers" tab of the item page (Spec 06 section 21.10). Always shown: when tracking is off
 * it explains who can switch it on (or offers the switch); when on it shows counts per status, a
 * searchable paged list, add/import (existing dialog, item locked), template, export, edit with a
 * reason, bulk move of available serials and the import history. Entry is for stock roles only
 * (serials.register); everyone else gets a read-only message. Loaded only when the tab is open.
 */
class ItemSerials extends Component
{
    use WithPagination;

    #[Locked]
    public int $itemId;

    public string $search = '';

    public string $status = '';

    /** @var list<int> selected serial ids (bulk move) */
    public array $selected = [];

    public ?int $moveWarehouseId = null;

    public string $moveReason = '';

    #[Locked]
    public ?int $editingId = null;

    /** @var array{serial: string, warehouse_id: string, warranty_until: string, note: string, reason: string} */
    public array $edit = ['serial' => '', 'warehouse_id' => '', 'warranty_until' => '', 'note' => '', 'reason' => ''];

    public function mount(int $itemId): void
    {
        $this->itemId = $itemId;
        $this->authorize('view', $this->item);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function item(): Item
    {
        return Item::withTrashed()->findOrFail($this->itemId);
    }

    /** The full list needs serials.view with scope "all" (sales see their own customers' serials elsewhere). */
    #[Computed]
    public function canList(): bool
    {
        return PermissionMap::scopeFor(auth()->user(), 'serials.view') === DataScope::All;
    }

    #[Computed]
    public function canRegister(): bool
    {
        return auth()->user()->can('register', ItemSerial::class);
    }

    /**
     * @return Collection<string, int>
     */
    #[Computed]
    public function counts(): Collection
    {
        return ItemSerial::query()->where('item_id', $this->itemId)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
    }

    /**
     * @return LengthAwarePaginator<int, ItemSerial>
     */
    #[Computed]
    public function serials(): LengthAwarePaginator
    {
        $term = mb_strtoupper(trim($this->search));

        return ItemSerial::query()->with('warehouse:id,name')->where('item_id', $this->itemId)
            ->when($term !== '', fn ($q) => $q->where('serial_normalized', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%'))
            ->when(SerialStatus::tryFrom($this->status) !== null, fn ($q) => $q->where('status', $this->status), fn ($q) => $q->where('status', '!=', SerialStatus::WrittenOff->value))
            ->orderBy('serial_normalized')
            ->paginate(25, pageName: 'serials-page');
    }

    /**
     * @return Collection<int, Warehouse>
     */
    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Registration receipts of this item (who, when, how many), newest first.
     *
     * @return Collection<int, GoodsReceipt>
     */
    #[Computed]
    public function history(): Collection
    {
        return GoodsReceipt::query()->with(['creator:id,name', 'lines' => fn ($q) => $q->where('item_id', $this->itemId)])
            ->where('source_type', ReceiptSource::ManualRegistration->value)
            ->whereHas('lines', fn ($q) => $q->where('item_id', $this->itemId))
            ->latest('id')->limit(20)->get();
    }

    public function toggleTracking(): void
    {
        $this->authorize('updateSerialTracking', $this->item);
        $this->item->update(['is_serialized' => ! $this->item->is_serialized]);
        unset($this->item);
        $this->dispatch('item-updated');
        session()->flash('status', __('catalog.items.codes_saved'));
    }

    public function startEdit(int $serialId): void
    {
        abort_unless($this->canRegister, 403);
        $serial = ItemSerial::query()->where('item_id', $this->itemId)->findOrFail($serialId);
        $this->editingId = $serial->id;
        $this->edit = ['serial' => $serial->serial_normalized, 'warehouse_id' => (string) $serial->warehouse_id, 'warranty_until' => (string) $serial->warranty_until?->toDateString(), 'note' => '', 'reason' => ''];
        $this->resetErrorBag();
    }

    public function saveEdit(CorrectSerial $correct): void
    {
        abort_unless($this->canRegister && $this->editingId !== null, 403);
        $this->validate([
            'edit.serial' => ['required', 'string', 'max:100'],
            'edit.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'edit.warranty_until' => ['nullable', 'date'],
            'edit.note' => ['nullable', 'string', 'max:255'],
            'edit.reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['edit.serial' => __('stock.serials.serial'), 'edit.reason' => __('stock.serials.edit.reason')]);

        try {
            $correct->handle(ItemSerial::query()->where('item_id', $this->itemId)->findOrFail($this->editingId), [
                'serial' => $this->edit['serial'],
                'warehouse_id' => $this->edit['warehouse_id'] !== '' ? (int) $this->edit['warehouse_id'] : null,
                'warranty_until' => $this->edit['warranty_until'] !== '' ? $this->edit['warranty_until'] : null,
                'note' => $this->edit['note'],
            ], $this->edit['reason'], auth()->user());
        } catch (StockActionException $exception) {
            $this->addError('edit.serial', $exception->getMessage());

            return;
        }

        $this->editingId = null;
        unset($this->serials, $this->counts);
        session()->flash('status', __('stock.serials.edit.saved'));
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    public function moveSelected(CorrectSerial $correct): void
    {
        abort_unless($this->canRegister, 403);
        $this->validate([
            'selected' => ['required', 'array', 'min:1'],
            'moveWarehouseId' => ['required', 'integer', 'exists:warehouses,id'],
            'moveReason' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['moveWarehouseId' => __('stock.serials.bulk.warehouse'), 'moveReason' => __('stock.serials.edit.reason'), 'selected' => __('stock.serials.bulk.selected')]);

        try {
            $moved = $correct->move(ItemSerial::query()->where('item_id', $this->itemId)->whereKey($this->selected)->get(), Warehouse::query()->findOrFail($this->moveWarehouseId), $this->moveReason, auth()->user());
        } catch (StockActionException $exception) {
            $this->addError('moveReason', $exception->getMessage());

            return;
        }

        $this->reset('selected', 'moveWarehouseId', 'moveReason');
        unset($this->serials);
        session()->flash('status', __('stock.serials.bulk.moved', ['count' => $moved]));
    }

    #[On('serials-registered')]
    public function refreshAfterRegistration(): void
    {
        unset($this->serials, $this->counts, $this->history);
    }

    public function render(): View
    {
        return view('livewire.catalog.item-serials', ['statuses' => SerialStatus::cases()]);
    }
}
