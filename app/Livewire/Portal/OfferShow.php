<?php

namespace App\Livewire\Portal;

use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\RecordCustomerSelection;
use App\Actions\Offers\TransitionOffer;
use App\Enums\CustomerPortalRole;
use App\Enums\OfferStatus;
use App\Enums\SelectionChannel;
use App\Models\AuthEvent;
use App\Models\Offer;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Offer in the portal (Spec 03 US-11, BR-P.4): the customer view with the option choices, "save
 * choices" and "accept" for buyers within the company threshold and approvers within their
 * limit (viewers and finance read only). The first visit marks a sent offer as viewed. The
 * acceptance records the portal user, channel, content hash and IP (AuthEvent) and creates the
 * order like an internal acceptance.
 */
#[Layout('layouts.portal')]
class OfferShow extends Component
{
    #[Locked]
    public int $offerId;

    /** @var array<int|string, mixed> group id => line id(s) */
    public array $previewSelection = [];

    public bool $confirming = false;

    public function mount(Offer $offer, PortalScope $scope, TransitionOffer $transition, PortalContext $context): void
    {
        abort_unless($scope->offers()->whereKey($offer->id)->exists(), 404);
        $this->offerId = $offer->id;

        if ($offer->status === OfferStatus::Sent) {
            $transition->handle($offer, OfferStatus::Viewed, $context->user(), 'viewed_in_portal');
        }

        $this->fillSelection();
    }

    #[Computed]
    public function offer(): Offer
    {
        return app(PortalScope::class)->offers()->with(['lines', 'groups', 'selections'])->findOrFail($this->offerId);
    }

    /**
     * @return Collection<int, mixed>
     */
    #[Computed]
    public function blocks(): Collection
    {
        return $this->offer->blocks();
    }

    protected function canChoose(): bool
    {
        return $this->offer->status->isOpen() && app(PortalContext::class)->hasRole(CustomerPortalRole::PortalAdmin, CustomerPortalRole::Buyer, CustomerPortalRole::Approver);
    }

    public function saveChoices(RecordCustomerSelection $record, PortalContext $context): void
    {
        abort_unless($this->canChoose(), 403);
        $choices = [];

        foreach ($this->offer->groups as $group) {
            $value = $this->previewSelection[$group->id] ?? [];
            $choices[$group->id] = array_values(array_filter(array_map('intval', is_array($value) ? $value : [$value])));
        }

        try {
            $record->handle($this->offer, $context->user(), $choices, SelectionChannel::Portal, null, null, $this->offer->selection_revision);
        } catch (OfferActionException $exception) {
            session()->flash('error', $exception->getMessage());
            unset($this->offer, $this->blocks);
            $this->fillSelection();

            return;
        }

        unset($this->offer, $this->blocks);
        session()->flash('status', __('portal.offers.choices_saved'));
    }

    public function accept(AcceptOffer $accept, PortalContext $context): void
    {
        abort_unless($this->canChoose(), 403);
        $offer = $this->offer;
        $amount = (int) ($offer->selected_gross_minor ?? $offer->fixed_gross_minor);

        if (! $context->canAccept($amount)) {
            session()->flash('error', __('portal.offers.above_limit'));

            return;
        }

        try {
            $accept->handle($offer, $context->user(), SelectionChannel::Portal, __('portal.offers.accepted_note', ['membership' => $context->membership()->uuid, 'ip' => (string) request()->ip()]));
        } catch (OfferActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        AuthEvent::record('portal', 'offer_accepted', $context->user());
        $this->confirming = false;
        unset($this->offer, $this->blocks);
        session()->flash('status', __('portal.offers.accepted'));
    }

    protected function fillSelection(): void
    {
        $this->previewSelection = [];

        foreach ($this->offer->groups as $group) {
            $ids = $this->offer->selections->where('option_group_id', $group->id)->pluck('offer_line_id')->map(fn ($id) => (string) $id)->values()->all();
            $this->previewSelection[$group->id] = $group->kind->value === 'alternative' && $group->max_select === 1 ? ($ids[0] ?? null) : $ids;
        }
    }

    public function render(): View
    {
        $offer = $this->offer;

        return view('livewire.portal.offer-show', [
            'totals' => [
                'fixed' => $offer->fixed_gross_minor, 'min' => $offer->range_min_gross_minor, 'max' => $offer->range_max_gross_minor,
                'recommended' => $offer->recommended_gross_minor, 'selected' => $offer->selected_gross_minor,
                'selected_net' => $offer->selected_net_minor, 'selected_vat' => $offer->selected_vat_minor, 'complete' => $offer->selection_complete,
            ],
            'interactive' => $this->canChoose(),
        ])->title($offer->displayNumber());
    }
}
