<?php

namespace App\Livewire\Tenders;

use App\Actions\Tenders\AwardTender;
use App\Enums\AwardStatus;
use App\Models\Tender;
use App\Models\TenderAward;
use App\Models\TenderComparison;
use App\Services\Tenders\ApprovalRouter;
use App\Services\Tenders\ComparisonBuilder;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Services\Tenders\TenderClock;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Comparison report and decision screen (Spec 08 9.1-9.4): the frozen snapshot an award proposal
 * is decided on (or a live preview during evaluation), tabs Summary / Table / Matrix / Scorecard /
 * Sensitivity / Evidence / Audit, and for the routed approver the decision panel with the
 * "reviewed" tick and step-up confirmation. Only after opening (AC-21); admins never.
 */
#[Layout('layouts.app')]
class Comparison extends Component
{
    #[Locked]
    public int $tenderId;

    #[Url(except: 'summary')]
    public string $tab = 'summary';

    #[Url(as: 'snapshot', except: '')]
    public string $snapshotUuid = '';

    public bool $reviewed = false;

    public string $comment = '';

    public string $password = '';

    public function mount(Tender $tender): void
    {
        $this->authorize('viewBids', $tender);
        $this->tenderId = $tender->id;
    }

    #[Computed]
    public function tender(): Tender
    {
        return Tender::query()->findOrFail($this->tenderId);
    }

    #[Computed]
    public function snapshot(): ?TenderComparison
    {
        $query = TenderComparison::query()->where('tender_id', $this->tenderId);

        return $this->snapshotUuid !== ''
            ? (clone $query)->where('uuid', $this->snapshotUuid)->first()
            : (clone $query)->whereIn('status', [TenderComparison::STATUS_SUBMITTED, TenderComparison::STATUS_DECIDED])->latest('id')->first();
    }

    /**
     * Report data: the snapshot core, else a live preview (not stored).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function report(): array
    {
        return $this->snapshot?->payload['core'] ?? app(ComparisonBuilder::class)->build($this->tender)['core'];
    }

    /**
     * Proposed awards the current user may decide.
     *
     * @return Collection<int, TenderAward>
     */
    #[Computed]
    public function decidable(): Collection
    {
        $router = app(ApprovalRouter::class);
        $tender = $this->tender;

        return TenderAward::query()->where('tender_id', $this->tenderId)->where('status', AwardStatus::Proposed)->with(['lot', 'counterparty'])->get()
            ->filter(fn (TenderAward $award): bool => collect($award->pendingLevels())->contains(fn ($level): bool => $router->canDecide(auth()->user(), $tender, $level, $award)))
            ->values();
    }

    public function decide(AwardTender $awards, int $awardId, bool $approve): void
    {
        $this->authorize('decideAward', $this->tender);

        if (! $this->reviewed) {
            $this->addError('reviewed', __('tenders.comparison.review_required'));

            return;
        }

        $award = $this->decidable->firstWhere('id', $awardId) ?? abort(403);

        try {
            $awards->decide($award, auth()->user(), $approve, trim($this->comment) ?: null, $this->password);
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->password = '';

            return;
        }

        $this->reset('password', 'comment', 'reviewed');
        unset($this->decidable, $this->snapshot, $this->report, $this->tender);
        session()->flash('status', __($approve ? 'tenders.flash.award_approved' : 'tenders.flash.award_rejected'));
    }

    public function render(): View
    {
        return view('livewire.tenders.comparison', [
            'tz' => TenderClock::label(),
            'history' => TenderComparison::query()->where('tender_id', $this->tenderId)->latest('id')->get(['id', 'uuid', 'version', 'round_no', 'status', 'payload_hash', 'generated_at']),
            'stepUp' => in_array($this->tender->type->value, (array) config('tenders.decision_step_up'), true),
        ])->title(__('tenders.comparison.title').' · '.$this->tender->number);
    }
}
