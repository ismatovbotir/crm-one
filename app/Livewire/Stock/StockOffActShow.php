<?php

namespace App\Livewire\Stock;

use App\Actions\StockOff\ManageStockOffAct;
use App\Actions\StockOff\TransitionStockOffAct;
use App\Enums\StockOffReason;
use App\Enums\StockOffStatus;
use App\Livewire\Concerns\HasDocumentTabs;
use App\Models\Item;
use App\Models\StockOffAct;
use App\Models\StockOffLine;
use App\Models\StockOffLineSerial;
use App\Services\Stock\Exceptions\StockOffException;
use App\Services\Stock\SerialNormalizer;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Stock-off act screen (Spec 06 27.6): stepper and allowed moves (submit, approve, reject,
 * rework, post, cancel), header (reason, note), lines (item + quantity, or picked serials),
 * estimated value and approval level, evidence files, supplier claim (receipt damage), history.
 *
 * Standard document tabs (2026-10-20): Header (reason, note, supplier claim), Items (search, lines,
 * serials) and Other (evidence files, history); summary, stepper and decision moves stay above.
 */
#[Layout('layouts.app')]
class StockOffActShow extends Component
{
    use HasDocumentTabs;

    #[Url(except: 'items')]
    public string $tab = 'items';

    use WithFileUploads;

    #[Locked]
    public int $actId;

    public string $itemSearch = '';

    public string $reasonField = '';

    public string $noteField = '';

    /** Reason for reject / cancel, or the approval note. */
    public string $moveNote = '';

    /** @var array<int, string> serial input per line id */
    public array $serialInput = [];

    /** @var array<int, string> quantity per non-serialized line id */
    public array $quantities = [];

    /** @var TemporaryUploadedFile|null */
    public $evidence = null;

    /** @var array{claim_status: string, claim_ref: string, claim_note: string} */
    public array $claimForm = ['claim_status' => 'none', 'claim_ref' => '', 'claim_note' => ''];

    /** @var list<array{serial: string, result: string, message: string}> */
    public array $results = [];

    public function mount(StockOffAct $act): void
    {
        $this->authorize('view', $act);
        $this->actId = $act->id;
        $this->fillForm();
    }

    #[Computed]
    public function act(): StockOffAct
    {
        return StockOffAct::query()->with(['lines.item', 'lines.serials', 'warehouse', 'goodsReceipt', 'supplierPartner', 'creator', 'decider', 'transitions.user', 'attachments'])->findOrFail($this->actId);
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

    /**
     * @return list<StockOffStatus>
     */
    #[Computed]
    public function moves(): array
    {
        $order = [StockOffStatus::Submitted, StockOffStatus::Approved, StockOffStatus::Posted, StockOffStatus::Draft, StockOffStatus::Rejected, StockOffStatus::Cancelled];

        return array_values(array_filter($order, fn (StockOffStatus $to): bool => auth()->user()->can('transition', [$this->act, $to])));
    }

    public function saveHeader(ManageStockOffAct $manage): void
    {
        $this->authorize('update', $this->act);
        $this->run(fn () => $manage->updateHeader($this->act, ['reason' => $this->reasonField, 'note' => $this->noteField]), 'stock_off.flash.saved');
    }

    public function addItem(ManageStockOffAct $manage, int $itemId): void
    {
        $this->authorize('update', $this->act);
        $item = Item::query()->findOrFail($itemId);
        $this->run(fn () => $manage->addLine($this->act, $item, '1'), null);
        $this->itemSearch = '';
    }

    public function saveQuantity(ManageStockOffAct $manage, int $lineId): void
    {
        $this->authorize('update', $this->act);
        $line = $this->line($lineId);
        $this->run(fn () => $manage->updateQuantity($line, (string) ($this->quantities[$lineId] ?? '')), 'stock_off.flash.saved');
    }

    public function removeLine(ManageStockOffAct $manage, int $lineId): void
    {
        $this->authorize('update', $this->act);
        $line = $this->line($lineId);
        $this->run(fn () => $manage->removeLine($line), null);
    }

    public function addSerials(ManageStockOffAct $manage, int $lineId): void
    {
        $this->authorize('update', $this->act);
        $line = $this->line($lineId);
        $inputs = app(SerialNormalizer::class)->split((string) ($this->serialInput[$lineId] ?? ''));

        if ($inputs === []) {
            return;
        }

        $this->run(function () use ($manage, $line, $inputs, $lineId): void {
            $this->results = $manage->addSerials($line, $inputs);

            if (collect($this->results)->every(fn (array $row): bool => $row['result'] === 'ok')) {
                $this->serialInput[$lineId] = '';
            }
        }, null);
    }

    public function removeSerial(ManageStockOffAct $manage, int $rowId): void
    {
        $this->authorize('update', $this->act);
        $row = StockOffLineSerial::query()->whereIn('line_id', $this->act->lines->pluck('id'))->findOrFail($rowId);
        $this->run(fn () => $manage->removeSerial($row), null);
    }

    public function uploadEvidence(ManageStockOffAct $manage): void
    {
        $this->authorize('addEvidence', $this->act);
        $this->validate(['evidence' => ['required', 'file']], [], ['evidence' => __('stock_off.fields.evidence')]);

        try {
            $manage->attachEvidence($this->act, $this->evidence, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('evidence', (string) collect($exception->errors())->flatten()->first());

            return;
        } catch (StockOffException $exception) {
            $this->addError('evidence', $exception->getMessage());

            return;
        }

        $this->evidence = null;
        unset($this->act);
        session()->flash('status', __('stock_off.flash.evidence_added'));
    }

    public function saveClaim(ManageStockOffAct $manage): void
    {
        $this->authorize('manageClaim', $this->act);
        $this->run(fn () => $manage->updateClaim($this->act, $this->claimForm), 'stock_off.flash.claim_saved');
    }

    public function move(TransitionStockOffAct $transition, string $to): void
    {
        $target = StockOffStatus::tryFrom($to) ?? abort(404);
        $this->authorize('transition', [$this->act, $target]);
        $this->run(fn () => $transition->handle($this->act, $target, auth()->user(), $this->moveNote), 'stock_off.flash.moved.'.$target->value);
        $this->moveNote = '';
    }

    protected function line(int $lineId): StockOffLine
    {
        return $this->act->lines->firstWhere('id', $lineId) ?? abort(404);
    }

    protected function run(callable $action, ?string $successKey): void
    {
        try {
            $action();
        } catch (StockOffException $exception) {
            session()->flash('error', $exception->getMessage());
            unset($this->act, $this->moves);

            return;
        }

        if ($successKey !== null) {
            session()->flash('status', __($successKey));
        }

        unset($this->act, $this->moves);
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $act = $this->act;
        $this->reasonField = $act->reason->value;
        $this->noteField = (string) $act->note;
        $this->claimForm = ['claim_status' => $act->claim_status, 'claim_ref' => (string) $act->claim_ref, 'claim_note' => (string) $act->claim_note];
        $this->quantities = [];

        foreach ($act->lines as $line) {
            $this->quantities[$line->id] = Quantity::trimmed((string) $line->quantity);
        }
    }

    protected function documentTabs(): array
    {
        $act = $this->act;
        $dirty = $this->reasonField !== $act->reason->value || trim($this->noteField) !== trim((string) $act->note);

        return [
            'header' => $this->documentTab(__('common.document_tabs.header'), null, $dirty ? __('common.document_tabs.unsaved') : null),
            'items' => $this->documentTab(__('common.document_tabs.items'), $act->lines->count()),
            'other' => $this->documentTab(__('common.document_tabs.other'), $act->attachments->count()),
        ];
    }

    public function render(): View
    {
        return view('livewire.stock.stock-off-act-show', [
            'reasons' => StockOffReason::options(),
        ])->title($this->act->number);
    }
}
