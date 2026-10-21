<?php

namespace App\Livewire\Requisitions;

use App\Actions\Requisitions\ManageRequisition;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use App\Services\Requisitions\Exceptions\RequisitionException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A customer request on our side (Spec 10 §6): lines (catalog items and category needs), mapping
 * of category lines to items, the shared clarification thread, decline, and "Create offer from
 * request" (prices come from the item sale price inside the offer, never shown to the customer
 * here). Client-internal comments are never loaded.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    #[Locked]
    public int $requisitionId;

    public string $comment = '';

    public string $declineReason = '';

    /** @var array<int, string> line id => search text */
    public array $mapSearch = [];

    /** @var array<int, string> line id => chosen item id */
    public array $mapItem = [];

    public function mount(Requisition $requisition): void
    {
        $this->authorize('view', $requisition);
        $this->requisitionId = $requisition->id;
    }

    #[Computed]
    public function requisition(): Requisition
    {
        return Requisition::query()->with([
            'organization', 'requester', 'purchaseManager', 'owner', 'attachments', 'offers',
            'lines.item', 'lines.category', 'lines.mappedItem',
            'comments' => fn ($q) => $q->where('audience', 'shared')->with('user'),
            'transitions.user',
        ])->findOrFail($this->requisitionId);
    }

    /**
     * @return Collection<int, Item>
     */
    public function optionsFor(int $lineId): Collection
    {
        $term = trim((string) ($this->mapSearch[$lineId] ?? ''));

        return mb_strlen($term) < 2 ? collect() : Item::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('sku', 'like', '%'.$term.'%'))->orderBy('name')->limit(15)->get(['id', 'name', 'sku']);
    }

    public function mapLine(int $lineId, ManageRequisition $manage): void
    {
        $this->authorize('manage', $this->requisition);
        $line = RequisitionLine::query()->where('requisition_id', $this->requisitionId)->findOrFail($lineId);
        $item = Item::query()->find((int) ($this->mapItem[$lineId] ?? 0));

        if ($item === null) {
            $this->addError('map.'.$lineId, __('requisitions.errors.choose_item'));

            return;
        }

        $this->run(fn () => $manage->mapLine($line, $item, auth()->user()));
    }

    public function addComment(ManageRequisition $manage): void
    {
        $this->authorize('manage', $this->requisition);
        $this->validate(['comment' => ['required', 'string', 'max:5000']]);
        $manage->comment($this->requisition, auth()->user(), $this->comment, 'shared');
        $this->reset('comment');
        unset($this->requisition);
    }

    public function decline(ManageRequisition $manage): void
    {
        $this->authorize('manage', $this->requisition);
        $this->run(fn () => $manage->decline($this->requisition, auth()->user(), $this->declineReason));
    }

    public function createOffer(ManageRequisition $manage): void
    {
        $this->authorize('manage', $this->requisition);

        try {
            $offer = $manage->createOffer($this->requisition, auth()->user());
        } catch (RequisitionException $exception) {
            $this->addError('offer', $exception->getMessage());

            return;
        }

        session()->flash('status', __('requisitions.flash.offer_created'));
        $this->redirectRoute('offers.edit', $offer, navigate: true);
    }

    protected function run(callable $action): void
    {
        try {
            $action();
            session()->flash('status', __('requisitions.flash.saved'));
        } catch (RequisitionException $exception) {
            $this->addError('offer', $exception->getMessage());
        }

        unset($this->requisition);
    }

    public function render(): View
    {
        $requisition = $this->requisition;

        return view('livewire.requisitions.show', [
            'requisition' => $requisition,
            'canManage' => auth()->user()->can('manage', $requisition),
        ])->title($requisition->number);
    }
}
