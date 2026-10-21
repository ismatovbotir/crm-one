<?php

namespace App\Livewire\Offers;

use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\CloseOffer;
use App\Actions\Offers\DecideOfferApproval;
use App\Actions\Offers\RecordCustomerSelection;
use App\Actions\Offers\ReviseOffer;
use App\Actions\Offers\SendOffer;
use App\Actions\Offers\SubmitOfferForApproval;
use App\Actions\Orders\CreateOrderFromOffer;
use App\Enums\OfferRejectReason;
use App\Enums\SelectionChannel;
use App\Livewire\Concerns\HasDocumentTabs;
use App\Models\Offer;
use App\Services\Offers\DiscountRules;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\Offers\OfferValidator;
use App\Services\Orders\Exceptions\OrderActionException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Offer detail (Spec 05 10.2, open offers): status actions (submit, approve/return, send,
 * record customer choice, accept, reject, revise, cancel). Standard document tabs (2026-10-20):
 * Header (contact, terms, texts, dates), Items (customer view with the current choices) and Other
 * (related documents, approvals, history; its own component, loaded when opened).
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use HasDocumentTabs;

    #[Locked]
    public int $offerId;

    #[Url(except: 'items')]
    public string $tab = 'items';

    /** Open action panel: send | selection | accept | reject | cancel | approve | return. */
    public string $panel = '';

    public string $channel = '';

    public string $note = '';

    public string $evidence = '';

    public string $comment = '';

    public string $rejectReason = '';

    /** @var array<int, int|string|list<int|string>> group id => selected line id(s) */
    public array $choices = [];

    #[Locked]
    public int $loadedRevision = 0;

    public function mount(Offer $offer): void
    {
        $this->authorize('view', $offer);

        $this->offerId = $offer->id;
    }

    #[Computed]
    public function offer(): Offer
    {
        return Offer::query()->with(['lines.item', 'lines.itemImage', 'groups', 'customer', 'contact', 'owner', 'selections'])->findOrFail($this->offerId);
    }

    /**
     * @return SupportCollection<int, array<string, mixed>>
     */
    #[Computed]
    public function blocks(): SupportCollection
    {
        return $this->offer->blocks();
    }

    /**
     * @return array{fixed: int, min: int, max: int, recommended: int|null, selected: int, selected_net: int, selected_vat: int, complete: bool}
     */
    #[Computed]
    public function totals(): array
    {
        $offer = $this->offer;

        return [
            'fixed' => $offer->fixed_gross_minor, 'min' => $offer->range_min_gross_minor, 'max' => $offer->range_max_gross_minor,
            'recommended' => $offer->recommended_gross_minor, 'selected' => $offer->selected_gross_minor,
            'selected_net' => $offer->selected_net_minor, 'selected_vat' => $offer->selected_vat_minor, 'complete' => $offer->selection_complete,
        ];
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function missingGroups(): array
    {
        return $this->offer->status->isOpen() ? RecordCustomerSelection::missingGroups($this->offer) : [];
    }

    /**
     * @return list<array{code: string, key: string, level: string, params: array<string, string|int>}>
     */
    #[Computed]
    public function issues(): array
    {
        return $this->offer->status->isEditable() ? app(OfferValidator::class)->validate($this->offer) : [];
    }

    #[Computed]
    public function approvalRequired(): bool
    {
        return app(DiscountRules::class)->requiresApproval($this->offer);
    }

    public function openPanel(string $panel): void
    {
        $this->resetValidation();
        $this->reset(['channel', 'note', 'evidence', 'comment', 'rejectReason']);
        $this->panel = in_array($panel, ['send', 'selection', 'accept', 'reject', 'cancel', 'approve', 'return'], true) ? $panel : '';

        if ($panel === 'selection') {
            $this->loadedRevision = $this->offer->selection_revision;
            $this->choices = [];

            foreach ($this->offer->groups as $group) {
                $selected = $this->offer->selections->where('option_group_id', $group->id)->pluck('offer_line_id')->map(fn (mixed $id): string => (string) $id)->values()->all();
                $this->choices[$group->id] = $group->max_select === 1 ? ($selected[0] ?? '') : $selected;
            }
        }
    }

    public function closePanel(): void
    {
        $this->panel = '';
        $this->resetValidation();
    }

    public function submit(SubmitOfferForApproval $submit): void
    {
        $this->authorize('submit', $this->offer);
        $this->run(fn () => $submit->handle($this->offer, auth()->user()), 'offers.flash.submitted');
    }

    public function approve(DecideOfferApproval $decide): void
    {
        $this->authorize('approve', $this->offer);
        $this->run(fn () => $decide->approve($this->offer, auth()->user(), $this->comment !== '' ? $this->comment : null), 'offers.flash.approved');
    }

    public function returnToDraft(DecideOfferApproval $decide): void
    {
        $this->authorize('approve', $this->offer);
        $this->validate(['comment' => ['required', 'string', 'max:2000']], [], ['comment' => __('offers.fields.comment')]);
        $this->run(fn () => $decide->returnToDraft($this->offer, auth()->user(), $this->comment), 'offers.flash.returned');
    }

    public function send(SendOffer $send): void
    {
        $this->authorize('send', $this->offer);
        $this->validate(['channel' => ['required', 'in:'.implode(',', array_map(fn (SelectionChannel $c) => $c->value, SelectionChannel::sending()))]], [], ['channel' => __('offers.fields.channel')]);
        $this->run(fn () => $send->handle($this->offer, auth()->user(), SelectionChannel::from($this->channel)), 'offers.flash.sent');
    }

    public function saveSelection(RecordCustomerSelection $record): void
    {
        $this->authorize('recordDecision', $this->offer);
        $this->validateDecision();

        $choices = [];

        foreach ($this->choices as $groupId => $value) {
            $choices[(int) $groupId] = array_values(array_filter(array_map('intval', is_array($value) ? $value : [$value])));
        }

        $this->run(fn () => $record->handle($this->offer, auth()->user(), $choices, SelectionChannel::from($this->channel), $this->blankToNull($this->note), $this->blankToNull($this->evidence), $this->loadedRevision), 'offers.flash.selection_saved');
    }

    public function accept(AcceptOffer $accept): void
    {
        $this->authorize('recordDecision', $this->offer);
        $this->validateDecision();
        $this->run(fn () => $accept->handle($this->offer, auth()->user(), SelectionChannel::from($this->channel), $this->blankToNull($this->note), $this->blankToNull($this->evidence)), 'offers.flash.accepted');
    }

    public function reject(CloseOffer $close): void
    {
        $this->authorize('recordDecision', $this->offer);
        $this->validate([
            'rejectReason' => ['required', 'in:'.implode(',', array_column(OfferRejectReason::cases(), 'value'))],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], [], ['rejectReason' => __('offers.fields.reject_reason'), 'comment' => __('offers.fields.comment')]);
        $this->run(fn () => $close->reject($this->offer, auth()->user(), OfferRejectReason::from($this->rejectReason), $this->blankToNull($this->comment)), 'offers.flash.rejected');
    }

    public function cancel(CloseOffer $close): void
    {
        $this->authorize('cancel', $this->offer);
        $this->validate(['comment' => ['required', 'string', 'max:2000']], [], ['comment' => __('offers.fields.cancel_reason')]);
        $this->run(fn () => $close->cancel($this->offer, auth()->user(), $this->comment), 'offers.flash.cancelled');
    }

    public function revise(ReviseOffer $revise): void
    {
        $this->authorize('revise', $this->offer);

        try {
            $draft = $revise->handle($this->offer, auth()->user());
        } catch (OfferActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('offers.flash.revised', ['number' => $draft->displayNumber()]));
        $this->redirectRoute('offers.edit', $draft, navigate: true);
    }

    public function createOrder(CreateOrderFromOffer $create): void
    {
        $this->authorize('createOrder', $this->offer);

        try {
            $order = $create->handle($this->offer, auth()->user());
        } catch (OrderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('orders.flash.created', ['number' => $order->number]));
        $this->redirectRoute('orders.show', $order, navigate: true);
    }

    protected function validateDecision(): void
    {
        $channel = SelectionChannel::tryFrom($this->channel);

        $this->validate([
            'channel' => ['required', 'in:'.implode(',', array_map(fn (SelectionChannel $c) => $c->value, SelectionChannel::internal()))],
            'note' => [$channel?->requiresNote() ? 'required' : 'nullable', 'string', 'max:2000'],
            'evidence' => [$channel?->requiresEvidence() ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [], [
            'channel' => __('offers.fields.channel'),
            'note' => __('offers.fields.note'),
            'evidence' => __('offers.fields.evidence'),
        ]);
    }

    protected function run(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (OfferActionException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->refresh();

            return;
        }

        session()->flash('status', __($successKey));
        $this->panel = '';
        $this->refresh();
    }

    protected function refresh(): void
    {
        unset($this->offer, $this->blocks, $this->totals, $this->missingGroups, $this->issues, $this->approvalRequired);
    }

    protected function blankToNull(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }

    protected function documentTabs(): array
    {
        return [
            'header' => __('common.document_tabs.header'),
            'items' => $this->documentTab(__('common.document_tabs.items'), $this->offer->lines->count()),
            'other' => __('common.document_tabs.other'),
        ];
    }

    protected function documentTabAliases(): array
    {
        return ['offer' => 'items', 'timeline' => 'other', 'versions' => 'other', 'approvals' => 'other'];
    }

    public function render(): View
    {
        return view('livewire.offers.show', [
            'internalChannels' => SelectionChannel::internal(),
            'sendingChannels' => SelectionChannel::sending(),
            'rejectReasons' => OfferRejectReason::options(),
        ])->title($this->offer->displayNumber());
    }
}
