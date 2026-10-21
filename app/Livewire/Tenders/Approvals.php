<?php

namespace App\Livewire\Tenders;

use App\Enums\TenderStatus;
use App\Models\Tender;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Approvals inbox (Spec 03 section 9, Spec 08 "Decisions waiting"): tenders waiting for my
 * publication approval and awards waiting for my level (head, CEO, finance). Decisions are taken
 * on the tender card.
 */
#[Layout('layouts.app')]
class Approvals extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->isInternal(), 403);
    }

    /**
     * @return Collection<int, Tender>
     */
    #[Computed]
    public function publications(): Collection
    {
        return Tender::query()->where('status', TenderStatus::PendingApproval)->with('owner')->orderBy('submitted_at')->get()
            ->filter(fn (Tender $tender): bool => auth()->user()->can('approvePublication', $tender))->values();
    }

    /**
     * @return Collection<int, Tender>
     */
    #[Computed]
    public function awards(): Collection
    {
        return Tender::query()->where('status', TenderStatus::AwardPendingApproval)->with(['owner', 'awards.counterparty'])->orderBy('updated_at')->get()
            ->filter(fn (Tender $tender): bool => auth()->user()->can('decideAward', $tender))->values();
    }

    public function render(): View
    {
        return view('livewire.tenders.approvals')->title(__('tenders.approvals.title'));
    }
}
