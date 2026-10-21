<?php

namespace App\Livewire\Stock;

use App\Actions\Stock\ManageGoodsReceipt;
use App\Actions\Stock\TransitionGoodsReceipt;
use App\Enums\DiscrepancyReason;
use App\Enums\GoodsReceiptStatus;
use App\Livewire\Concerns\HasDocumentTabs;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Services\Stock\Exceptions\StockActionException;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Goods receipt screen (Spec 06 21.10, 26.4): status stepper and the allowed moves (start, mark
 * checked, reopen, hold, release, post, cancel), lines with expected (from 1C, read-only) /
 * received / damaged quantities, note and discrepancy reason, serial registration per serialized
 * line, the 1C review flag and the history.
 *
 * Standard document tabs (2026-10-20): Header (source, supplier, 1C document), Items (search,
 * lines, serials) and Other (history); summary, stepper, review flag and moves stay above.
 */
#[Layout('layouts.app')]
class GoodsReceiptShow extends Component
{
    use HasDocumentTabs;

    #[Url(except: 'items')]
    public string $tab = 'items';

    #[Locked]
    public int $receiptId;

    public string $itemSearch = '';

    /** @var array<int, array{expected_qty: string, received_qty: string, damaged_qty: string, discrepancy_reason: string, note: string}> */
    public array $edits = [];

    /** Reason for hold / cancel, or the note that resolves a 1C review flag. */
    public string $reason = '';

    public function mount(GoodsReceipt $receipt): void
    {
        $this->authorize('view', $receipt);
        $this->receiptId = $receipt->id;
        $this->fillEdits();
    }

    #[Computed]
    public function receipt(): GoodsReceipt
    {
        return GoodsReceipt::query()->with(['lines.item', 'warehouse', 'returnOfDelivery', 'customer', 'supplierPartner', 'transitions.user'])->findOrFail($this->receiptId);
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function itemOptions(): Collection
    {
        $term = trim($this->itemSearch);

        return $term === '' ? new Collection : Item::query()->where(fn ($q) => $q->where('sku', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%'))->orderBy('name')->limit(10)->get(['id', 'sku', 'name', 'is_serialized']);
    }

    public function addItem(ManageGoodsReceipt $manage, int $itemId): void
    {
        $this->authorize('update', $this->receipt);
        $this->run(fn () => $manage->addLine($this->receipt, Item::query()->findOrFail($itemId), null, '0'), null);
        $this->itemSearch = '';
    }

    public function saveLine(ManageGoodsReceipt $manage, int $lineId): void
    {
        $this->authorize('update', $this->receipt);
        $line = $this->receipt->lines->firstWhere('id', $lineId) ?? abort(404);
        $edit = $this->edits[$lineId] ?? [];
        $this->run(fn () => $manage->updateLine($line, [
            'expected_qty' => $edit['expected_qty'] ?? null,
            'received_qty' => $edit['received_qty'] ?? '0',
            'damaged_qty' => ($edit['damaged_qty'] ?? '') === '' ? '0' : $edit['damaged_qty'],
            'discrepancy_reason' => $edit['discrepancy_reason'] ?? null,
            'note' => trim((string) ($edit['note'] ?? '')) === '' ? null : mb_substr(trim((string) $edit['note']), 0, 500),
        ]), 'stock.flash.line_saved');
    }

    public function removeLine(ManageGoodsReceipt $manage, int $lineId): void
    {
        $this->authorize('update', $this->receipt);
        $line = $this->receipt->lines->firstWhere('id', $lineId) ?? abort(404);
        $this->run(fn () => $manage->removeLine($line), null);
    }

    public function move(TransitionGoodsReceipt $transition, string $to): void
    {
        $target = GoodsReceiptStatus::tryFrom($to) ?? abort(404);
        $this->authorize('transition', [$this->receipt, $target]);
        $this->run(fn () => $transition->handle($this->receipt, $target, auth()->user(), $this->reason), 'stock.flash.receipt_moved.'.$target->value);
        $this->reason = '';
    }

    public function post(TransitionGoodsReceipt $transition): void
    {
        $this->move($transition, GoodsReceiptStatus::Posted->value);
    }

    public function resolveReview(ManageGoodsReceipt $manage): void
    {
        $this->authorize('resolveReview', $this->receipt);
        $this->run(fn () => $manage->resolveReview($this->receipt, auth()->user(), $this->reason), 'stock.flash.review_resolved');
        $this->reason = '';
    }

    /**
     * Moves the signed-in user may make now, in display order.
     *
     * @return list<GoodsReceiptStatus>
     */
    #[Computed]
    public function moves(): array
    {
        $order = [GoodsReceiptStatus::InProgress, GoodsReceiptStatus::Checked, GoodsReceiptStatus::Posted, GoodsReceiptStatus::OnHold, GoodsReceiptStatus::Cancelled];

        return array_values(array_filter($order, fn (GoodsReceiptStatus $to): bool => auth()->user()->can('transition', [$this->receipt, $to])));
    }

    #[On('serials-updated')]
    public function serialsUpdated(): void
    {
        unset($this->receipt);
        $this->fillEdits();
    }

    protected function run(callable $action, ?string $successKey): void
    {
        try {
            $action();
        } catch (StockActionException $exception) {
            session()->flash('error', $exception->getMessage());
            unset($this->receipt);

            return;
        }

        if ($successKey !== null) {
            session()->flash('status', __($successKey));
        }

        unset($this->receipt, $this->moves);
        $this->fillEdits();
    }

    protected function fillEdits(): void
    {
        $this->edits = [];

        foreach ($this->receipt->lines as $line) {
            $this->edits[$line->id] = [
                'expected_qty' => $line->expected_qty !== null ? Quantity::trimmed((string) $line->expected_qty) : '',
                'received_qty' => Quantity::trimmed((string) $line->received_qty),
                'damaged_qty' => Quantity::trimmed((string) $line->damaged_qty),
                'discrepancy_reason' => (string) ($line->discrepancy_reason?->value ?? ''),
                'note' => (string) $line->note,
            ];
        }
    }

    protected function documentTabs(): array
    {
        return [
            'header' => __('common.document_tabs.header'),
            'items' => $this->documentTab(__('common.document_tabs.items'), $this->receipt->lines->count()),
            'other' => $this->documentTab(__('common.document_tabs.other'), $this->receipt->transitions->count()),
        ];
    }

    public function render(): View
    {
        return view('livewire.stock.goods-receipt-show', [
            'reasons' => DiscrepancyReason::options(),
            'serialLineType' => GoodsReceiptLine::LINE_TYPE,
        ])->title($this->receipt->number);
    }
}
