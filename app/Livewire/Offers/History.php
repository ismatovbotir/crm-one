<?php

namespace App\Livewire\Offers;

use App\Models\ActivityLog;
use App\Models\Offer;
use App\Models\OfferApproval;
use App\Models\OfferSelectionEvent;
use App\Models\StatusTransition;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Other" tab of the offer pages (builder and detail): related documents (lead, versions, order),
 * approvals and the timeline (status transitions, selection events, activity). Rendered only when
 * the tab is opened.
 */
class History extends Component
{
    #[Locked]
    public int $offerId;

    public function mount(int $offerId): void
    {
        $this->offerId = $offerId;
        $this->authorize('view', $this->offer);
    }

    #[Computed]
    public function offer(): Offer
    {
        return Offer::query()->with(['lead', 'order'])->findOrFail($this->offerId);
    }

    /**
     * @return SupportCollection<int, array{at: Carbon, kind: string, model: mixed}>
     */
    #[Computed]
    public function timeline(): SupportCollection
    {
        $offer = $this->offer;
        $items = collect();

        foreach (StatusTransition::query()->with('user')->where('subject_type', 'offer')->where('subject_id', $offer->id)->get() as $transition) {
            $items->push(['at' => $transition->created_at, 'kind' => 'transition', 'model' => $transition]);
        }

        foreach (OfferSelectionEvent::query()->with(['user', 'line'])->where('offer_id', $offer->id)->get() as $event) {
            $items->push(['at' => $event->created_at, 'kind' => 'selection', 'model' => $event]);
        }

        if (auth()->user()->can('viewActivity', $offer)) {
            foreach (ActivityLog::query()->with('causer')->where('subject_type', 'offer')->where('subject_id', $offer->id)->whereNotIn('event', ['status_changed'])->get() as $entry) {
                $items->push(['at' => $entry->created_at, 'kind' => 'activity', 'model' => $entry]);
            }
        }

        return $items->sortByDesc(fn (array $item) => $item['at']->getTimestamp())->values();
    }

    /**
     * @return Collection<int, Offer>
     */
    #[Computed]
    public function versions(): Collection
    {
        return $this->offer->versions()->filter(fn (Offer $version): bool => auth()->user()->can('view', $version))->values();
    }

    /**
     * @return Collection<int, OfferApproval>
     */
    #[Computed]
    public function approvals(): Collection
    {
        return $this->offer->approvals()->with(['requester', 'decider'])->get();
    }

    public function render(): View
    {
        return view('livewire.offers.history');
    }
}
