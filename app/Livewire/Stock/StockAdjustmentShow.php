<?php

namespace App\Livewire\Stock;

use App\Actions\StockAdjustment\ManageStockAdjustment;
use App\Actions\StockAdjustment\TransitionStockAdjustment;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockAdjustmentType;
use App\Models\Delivery;
use App\Models\DocumentLineSerial;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Services\Stock\Exceptions\StockAdjustmentException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Stock adjustment screen (Spec 06 21.4): stepper and allowed moves (submit, approve and post,
 * reject, rework, cancel), the reason, the lines of its type (found serials, delivery note serial
 * replacements, signed quantities) and the history. The approver never decides their own request.
 */
#[Layout('layouts.app')]
class StockAdjustmentShow extends Component
{
    #[Locked]
    public int $adjustmentId;

    public string $reasonField = '';

    /** Reason for reject / cancel, or the approval note. */
    public string $moveNote = '';

    public string $itemSearch = '';

    public ?int $itemId = null;

    public string $serialInput = '';

    public string $quantityInput = '';

    public string $lineNote = '';

    public ?int $rowId = null;

    public string $newSerial = '';

    public string $outcome = 'available';

    public function mount(StockAdjustment $adjustment): void
    {
        $this->authorize('view', $adjustment);
        $this->adjustmentId = $adjustment->id;
        $this->reasonField = $adjustment->reason;
    }

    #[Computed]
    public function adjustment(): StockAdjustment
    {
        return StockAdjustment::query()->with(['lines.item', 'lines.oldSerial', 'lines.newSerial', 'warehouse', 'delivery', 'creator', 'decider', 'transitions.user'])->findOrFail($this->adjustmentId);
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function itemOptions(): Collection
    {
        $term = trim($this->itemSearch);
        $serialized = $this->adjustment->type === StockAdjustmentType::Found;

        return $term === '' ? new Collection : Item::query()->where('is_serialized', $serialized)
            ->where(fn ($q) => $q->where('sku', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%'))->orderBy('name')->limit(10)->get(['id', 'sku', 'name', 'is_serialized']);
    }

    #[Computed]
    public function selectedItem(): ?Item
    {
        return $this->itemId !== null ? Item::query()->find($this->itemId, ['id', 'sku', 'name', 'is_serialized']) : null;
    }

    /**
     * Posted serial rows of the delivery note that can still be replaced.
     *
     * @return Collection<int, DocumentLineSerial>
     */
    #[Computed]
    public function deliverySerials(): Collection
    {
        if ($this->adjustment->type !== StockAdjustmentType::SerialCorrection || $this->adjustment->delivery_id === null) {
            return new Collection;
        }

        return DocumentLineSerial::query()->where('document_type', Delivery::DOCUMENT_TYPE)->where('document_id', $this->adjustment->delivery_id)
            ->where('status', DocumentLineSerial::POSTED)->orderBy('id')->get();
    }

    /**
     * @return list<StockAdjustmentStatus>
     */
    #[Computed]
    public function moves(): array
    {
        $order = [StockAdjustmentStatus::PendingApproval, StockAdjustmentStatus::Posted, StockAdjustmentStatus::Draft, StockAdjustmentStatus::Rejected, StockAdjustmentStatus::Cancelled];

        return array_values(array_filter($order, fn (StockAdjustmentStatus $to): bool => auth()->user()->can('transition', [$this->adjustment, $to])));
    }

    public function saveReason(ManageStockAdjustment $manage): void
    {
        $this->authorize('update', $this->adjustment);
        $this->run(fn () => $manage->updateReason($this->adjustment, $this->reasonField), 'stock_adjustments.flash.saved');
    }

    public function pickItem(int $itemId): void
    {
        $this->itemId = $itemId;
        $this->itemSearch = '';
        unset($this->itemOptions, $this->selectedItem);
    }

    public function addFound(ManageStockAdjustment $manage): void
    {
        $this->authorize('update', $this->adjustment);
        $this->run(function () use ($manage): void {
            $item = $this->selectedItem ?? throw new StockAdjustmentException('item_required');
            $manage->addFoundSerial($this->adjustment, $item, $this->serialInput);
            $this->serialInput = '';
        }, null);
    }

    public function addQuantity(ManageStockAdjustment $manage): void
    {
        $this->authorize('update', $this->adjustment);
        $this->run(function () use ($manage): void {
            $item = $this->selectedItem ?? throw new StockAdjustmentException('item_required');
            $manage->addQuantity($this->adjustment, $item, $this->quantityInput, $this->lineNote);
            $this->quantityInput = '';
            $this->lineNote = '';
            $this->itemId = null;
            unset($this->selectedItem);
        }, null);
    }

    public function addCorrection(ManageStockAdjustment $manage): void
    {
        $this->authorize('update', $this->adjustment);
        $this->run(function () use ($manage): void {
            $row = $this->deliverySerials->firstWhere('id', $this->rowId) ?? throw new StockAdjustmentException('row_not_on_delivery');
            $manage->addCorrection($this->adjustment, $row, $this->newSerial, $this->outcome);
            $this->newSerial = '';
            $this->rowId = null;
        }, null);
    }

    public function removeLine(ManageStockAdjustment $manage, int $lineId): void
    {
        $this->authorize('update', $this->adjustment);
        $line = $this->adjustment->lines->firstWhere('id', $lineId) ?? abort(404);
        $this->run(fn () => $manage->removeLine($line), null);
    }

    public function move(TransitionStockAdjustment $transition, string $to): void
    {
        $target = StockAdjustmentStatus::tryFrom($to) ?? abort(404);
        $this->authorize('transition', [$this->adjustment, $target]);
        $this->run(fn () => $transition->handle($this->adjustment, $target, auth()->user(), $this->moveNote), 'stock_adjustments.flash.moved.'.$target->value);
        $this->moveNote = '';
    }

    protected function run(callable $action, ?string $successKey): void
    {
        try {
            $action();
        } catch (StockAdjustmentException $exception) {
            session()->flash('error', $exception->getMessage());
            unset($this->adjustment, $this->moves, $this->deliverySerials);

            return;
        }

        if ($successKey !== null) {
            session()->flash('status', __($successKey));
        }

        unset($this->adjustment, $this->moves, $this->deliverySerials);
        $this->reasonField = $this->adjustment->reason;
    }

    public function lineLabel(StockAdjustmentLine $line): string
    {
        return match ($this->adjustment->type) {
            StockAdjustmentType::Found => (string) $line->serial_normalized,
            StockAdjustmentType::SerialCorrection => $line->oldSerial?->serial_normalized.' → '.$line->serial_normalized,
            StockAdjustmentType::QtyCorrection => ((string) $line->quantity > 0 ? '+' : '').rtrim(rtrim((string) $line->quantity, '0'), '.'),
        };
    }

    public function render(): View
    {
        return view('livewire.stock.stock-adjustment-show')->title($this->adjustment->number);
    }
}
