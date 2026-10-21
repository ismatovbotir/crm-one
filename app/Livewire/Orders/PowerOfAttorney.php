<?php

namespace App\Livewire\Orders;

use App\Actions\Orders\RecordPowerOfAttorney;
use App\Models\Order;
use App\Services\Orders\Exceptions\OrderActionException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Power of attorney received" checkbox on the order page (Spec 06 section 28): tick with an
 * optional note, untick / change the requirement / override the tax-invoice gate with a reason.
 * Shown for B2B orders only; when not required it only offers to require it.
 */
class PowerOfAttorney extends Component
{
    #[Locked]
    public int $orderId;

    public string $note = '';

    public string $reason = '';

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
        $this->authorize('view', $this->order);
    }

    #[Computed]
    public function order(): Order
    {
        return Order::query()->with(['owner'])->findOrFail($this->orderId);
    }

    public function tick(RecordPowerOfAttorney $record): void
    {
        $this->authorize('tickPoa', $this->order);
        $this->run(fn () => $record->tick($this->order, auth()->user(), $this->note), 'orders.poa.flash.ticked');
    }

    public function untick(RecordPowerOfAttorney $record): void
    {
        $this->authorize('untickPoa', $this->order);
        $this->run(fn () => $record->untick($this->order, auth()->user(), $this->reason), 'orders.poa.flash.unticked');
    }

    public function toggleRequired(RecordPowerOfAttorney $record): void
    {
        $this->authorize('changePoaRequirement', $this->order);
        $this->run(fn () => $record->setRequired($this->order, ! $this->order->poa_required, auth()->user(), $this->reason), 'orders.poa.flash.requirement');
    }

    public function override(RecordPowerOfAttorney $record): void
    {
        $this->authorize('overridePoa', $this->order);
        $this->run(fn () => $record->override($this->order, auth()->user(), $this->reason), 'orders.poa.flash.overridden');
    }

    protected function run(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (OrderActionException $exception) {
            $this->addError('reason', $exception->getMessage());

            return;
        }

        $this->reset('note', 'reason');
        unset($this->order);
        session()->flash('status', __($successKey));
        $this->redirectRoute('orders.show', $this->order, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.orders.power-of-attorney');
    }
}
