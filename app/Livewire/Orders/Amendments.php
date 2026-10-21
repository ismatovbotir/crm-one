<?php

namespace App\Livewire\Orders;

use App\Actions\OrderAmendments\ManageOrderAmendment;
use App\Actions\OrderAmendments\TransitionOrderAmendment;
use App\Enums\OrderAmendmentType;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Services\Orders\Exceptions\OrderAmendmentException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Order amendments (Spec 06 section 6.4): the open draft with its changes (quantity, discount, new
 * line, remove line, close short), submit / approve / reject / cancel, and the history with the
 * totals before and after and the addendum draft of a binding agreement.
 */
#[Layout('layouts.app')]
class Amendments extends Component
{
    #[Locked]
    public int $orderId;

    public string $reason = '';

    public string $moveNote = '';

    public string $type = 'qty_change';

    public ?int $lineId = null;

    public string $quantity = '';

    /** Discount in percent as typed (12.5). */
    public string $discount = '';

    public string $itemSearch = '';

    public ?int $itemId = null;

    public function mount(Order $order): void
    {
        $this->authorize('view', $order);
        $this->orderId = $order->id;
    }

    #[Computed]
    public function order(): Order
    {
        return Order::query()->with(['lines.item', 'customer', 'amendments.requester', 'amendments.decider', 'amendments.addendum'])->findOrFail($this->orderId);
    }

    #[Computed]
    public function open(): ?OrderAmendment
    {
        return $this->order->amendments->first(fn (OrderAmendment $amendment): bool => $amendment->status->isOpen());
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

    #[Computed]
    public function selectedItem(): ?Item
    {
        return $this->itemId !== null ? Item::query()->find($this->itemId, ['id', 'sku', 'name']) : null;
    }

    public function canRequest(): bool
    {
        return auth()->user()->can('amend', $this->order);
    }

    public function canDecide(): bool
    {
        return $this->open !== null && app(TransitionOrderAmendment::class)->canDecide(auth()->user(), $this->open);
    }

    public function canReject(): bool
    {
        return $this->open !== null && app(TransitionOrderAmendment::class)->canReject(auth()->user(), $this->open);
    }

    public function start(ManageOrderAmendment $manage): void
    {
        $this->authorize('amend', $this->order);
        $this->run(function () use ($manage): void {
            $manage->start($this->order, auth()->user(), $this->reason);
            $this->reason = '';
        }, 'order_amendments.flash.started');
    }

    public function pickItem(int $itemId): void
    {
        $this->itemId = $itemId;
        $this->itemSearch = '';
        unset($this->itemOptions, $this->selectedItem);
    }

    public function addChange(ManageOrderAmendment $manage): void
    {
        $this->authorize('amend', $this->order);
        $open = $this->open ?? throw new OrderAmendmentException('not_editable');
        $this->run(function () use ($manage, $open): void {
            $manage->addChange($open, [
                'type' => $this->type,
                'order_line_id' => $this->lineId,
                'item_id' => $this->itemId,
                'quantity' => $this->quantity,
                'discount_bp' => $this->discount === '' ? 0 : (int) round(((float) str_replace(',', '.', $this->discount)) * 100),
            ]);
            $this->quantity = '';
            $this->discount = '';
            $this->lineId = null;
            $this->itemId = null;
            unset($this->selectedItem);
        }, null);
    }

    public function removeChange(ManageOrderAmendment $manage, int $index): void
    {
        $this->authorize('amend', $this->order);
        $open = $this->open ?? throw new OrderAmendmentException('not_editable');
        $this->run(fn () => $manage->removeChange($open, $index), null);
    }

    public function submit(TransitionOrderAmendment $transition): void
    {
        $this->authorize('amend', $this->order);
        $open = $this->open ?? throw new OrderAmendmentException('not_editable');
        $this->run(function () use ($transition, $open): void {
            $amendment = $transition->submit($open, auth()->user());
            session()->flash('status', __('order_amendments.flash.'.($amendment->status->value === 'applied' ? 'applied' : 'submitted')));
        }, null);
    }

    public function approve(TransitionOrderAmendment $transition): void
    {
        $open = $this->open ?? throw new OrderAmendmentException('not_editable');
        $this->run(fn () => $transition->approve($open, auth()->user(), $this->moveNote), 'order_amendments.flash.applied');
        $this->moveNote = '';
    }

    public function reject(TransitionOrderAmendment $transition): void
    {
        $open = $this->open ?? throw new OrderAmendmentException('not_editable');
        $this->run(fn () => $transition->reject($open, auth()->user(), $this->moveNote), 'order_amendments.flash.rejected');
        $this->moveNote = '';
    }

    public function cancel(TransitionOrderAmendment $transition): void
    {
        $open = $this->open ?? throw new OrderAmendmentException('not_editable');
        $this->run(fn () => $transition->cancel($open, auth()->user(), $this->moveNote), 'order_amendments.flash.cancelled');
        $this->moveNote = '';
    }

    protected function run(callable $action, ?string $successKey): void
    {
        try {
            $action();
        } catch (OrderAmendmentException $exception) {
            session()->flash('error', $exception->getMessage());
            unset($this->order, $this->open);

            return;
        }

        if ($successKey !== null) {
            session()->flash('status', __($successKey));
        }

        unset($this->order, $this->open);
    }

    public function render(): View
    {
        return view('livewire.orders.amendments', ['types' => OrderAmendmentType::options()])->title(__('order_amendments.title', ['number' => $this->order->number]));
    }
}
