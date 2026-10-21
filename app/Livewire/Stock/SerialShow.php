<?php

namespace App\Livewire\Stock;

use App\Actions\Stock\ManageSerials;
use App\Models\ItemSerial;
use App\Services\Stock\Exceptions\StockActionException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Serial detail (Spec 06 21.10): item, status, warehouse, sale (customer only when allowed),
 * warranty and the full history; inspection of returned / quarantined units.
 */
#[Layout('layouts.app')]
class SerialShow extends Component
{
    #[Locked]
    public int $serialId;

    public string $note = '';

    public function mount(ItemSerial $serial): void
    {
        $this->authorize('view', $serial);
        $this->serialId = $serial->id;
    }

    #[Computed]
    public function serial(): ItemSerial
    {
        return ItemSerial::query()->with(['item', 'warehouse', 'customer', 'soldDeliveryLine.delivery', 'receiptLine.receipt', 'events.causer'])->findOrFail($this->serialId);
    }

    public function inspect(ManageSerials $serials): void
    {
        $this->authorize('inspect', $this->serial);

        try {
            $serials->inspect($this->serial, auth()->user(), trim($this->note) ?: null);
        } catch (StockActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        unset($this->serial);
        session()->flash('status', __('stock.flash.inspected'));
    }

    public function render(): View
    {
        return view('livewire.stock.serial-show')->title($this->serial->serial_normalized);
    }
}
