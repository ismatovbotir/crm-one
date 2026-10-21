<?php

namespace App\Livewire\Tenders;

use App\Actions\Tenders\AwardTender;
use App\Actions\Tenders\EvaluateTender;
use App\Enums\AwardStatus;
use App\Enums\ChargeInclusion;
use App\Enums\CriterionMethod;
use App\Enums\TenderStatus;
use App\Models\Tender;
use App\Models\TenderAward;
use App\Models\TenderBid;
use App\Models\TenderBidLine;
use App\Models\TenderBidLot;
use App\Models\TenderCriterion;
use App\Services\Tenders\ApprovalRouter;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Services\Tenders\TenderEvaluator;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Evaluation and award (Spec 03 section 9, BR-T.8-T.11): frozen FX (finance), owner estimates
 * for not-included charges, knock-outs and disqualification, committee scores, the per-lot
 * scorecard (landed cost, criterion scores, weighted total, rank), award proposal with
 * justification, approvals per routed level, the winner's acceptance and the purchase order.
 * Content is shown only after opening to users allowed to see bids.
 */
class Evaluation extends Component
{
    #[Locked]
    public int $tenderId;

    /** @var array<string, string> */
    public array $fx = [];

    public string $fxDate = '';

    /** @var array<int, string> line id => estimate (major) */
    public array $estimates = [];

    /** @var array<string, string> "bidLot:criterion" => score */
    public array $scores = [];

    /** @var array<int, array{bid_lot_id: string, justification: string}> */
    public array $selection = [];

    public ?int $disqualifyBidId = null;

    public string $disqualifyReason = '';

    /** @var array<int, string> award id => comment */
    public array $decisionComments = [];

    /** @var array<int, string> award id => note */
    public array $acceptanceNotes = [];

    /** @var list<array{key: string, params: array<string, string|int>}> */
    public array $problems = [];

    public function mount(int $tenderId): void
    {
        $this->tenderId = $tenderId;
        $this->authorize('view', $this->tender);
        $this->fxDate = (string) ($this->tender->opened_at?->setTimezone((string) config('tenders.timezone'))->toDateString() ?? now()->toDateString());

        foreach ((array) $this->tender->fx_rates as $currency => $row) {
            $this->fx[$currency] = (string) ($row['rate'] ?? '');
        }

        if ($this->tender->status === TenderStatus::Evaluation && auth()->user()->can('viewBids', $this->tender)) {
            $this->problems = app(EvaluateTender::class)->recompute($this->tender);
            unset($this->tender);
        }

        $this->fillInputs();
    }

    #[Computed]
    public function tender(): Tender
    {
        return Tender::query()->with(['lots.transportSpec', 'criteria'])->findOrFail($this->tenderId);
    }

    /**
     * @return Collection<int, TenderBid>
     */
    #[Computed]
    public function bids(): Collection
    {
        return TenderEvaluator::currentBids($this->tender);
    }

    /**
     * @return Collection<int, TenderAward>
     */
    #[Computed]
    public function awards(): Collection
    {
        return TenderAward::query()->where('tender_id', $this->tenderId)->with(['lot', 'counterparty', 'decisions.decider', 'purchaseOrder'])->orderBy('lot_id')->orderByDesc('id')->get();
    }

    /**
     * Currencies that need a frozen rate: bid currencies, the cargo currency and the evaluation currency.
     *
     * @return list<string>
     */
    protected function neededCurrencies(): array
    {
        $currencies = $this->bids->pluck('currency')->all();

        foreach ($this->tender->lots as $lot) {
            if ($lot->transportSpec?->cargo_currency) {
                $currencies[] = $lot->transportSpec->cargo_currency;
            }
        }

        $currencies[] = $this->tender->evaluation_currency;

        return array_values(array_diff(array_unique(array_map('strtoupper', $currencies)), ['UZS']));
    }

    public function saveFx(EvaluateTender $evaluate): void
    {
        $this->authorize('setFx', $this->tender);
        $this->validate(['fxDate' => ['required', 'date'], 'fx.*' => ['nullable', 'string', 'max:20']]);
        $this->run(fn () => $evaluate->setFx($this->tender, $this->fx, $this->fxDate, auth()->user()), 'tenders.flash.fx_saved');
    }

    public function saveEstimate(EvaluateTender $evaluate, int $lineId): void
    {
        $this->authorize('evaluate', $this->tender);
        $line = TenderBidLine::query()->whereHas('bidLot.bid', fn ($q) => $q->where('tender_id', $this->tenderId))->findOrFail($lineId);
        $this->run(fn () => $evaluate->setEstimate($line, $this->estimates[$lineId] ?? null, auth()->user()), 'tenders.flash.saved');
    }

    public function saveScore(EvaluateTender $evaluate, int $bidLotId, int $criterionId): void
    {
        $this->authorize('score', $this->tender);
        $bidLot = TenderBidLot::query()->with('bid.tender')->whereHas('bid', fn ($q) => $q->where('tender_id', $this->tenderId))->findOrFail($bidLotId);
        $criterion = TenderCriterion::query()->where('tender_id', $this->tenderId)->findOrFail($criterionId);
        $this->run(fn () => $evaluate->score($bidLot, $criterion, (string) ($this->scores[$bidLotId.':'.$criterionId] ?? ''), null, auth()->user()), 'tenders.flash.saved');
    }

    public function disqualify(EvaluateTender $evaluate): void
    {
        $this->authorize('evaluate', $this->tender);
        $bid = TenderBid::query()->where('tender_id', $this->tenderId)->findOrFail((int) $this->disqualifyBidId);
        $this->run(fn () => $evaluate->disqualify($bid, $this->disqualifyReason, auth()->user()), 'tenders.flash.saved');
        $this->reset('disqualifyBidId', 'disqualifyReason');
    }

    public function requalify(EvaluateTender $evaluate, int $bidId): void
    {
        $this->authorize('evaluate', $this->tender);
        $this->run(fn () => $evaluate->requalify(TenderBid::query()->where('tender_id', $this->tenderId)->findOrFail($bidId), auth()->user()), 'tenders.flash.saved');
    }

    public function propose(AwardTender $award): void
    {
        $this->authorize('proposeAward', $this->tender);
        $selection = [];

        foreach ($this->selection as $lotId => $choice) {
            if (filled($choice['bid_lot_id'] ?? null)) {
                $selection[(int) $lotId] = ['bid_lot_id' => (int) $choice['bid_lot_id'], 'justification' => (string) ($choice['justification'] ?? '')];
            }
        }

        $this->run(fn () => $award->propose($this->tender, $selection, auth()->user()), 'tenders.flash.award_proposed');
    }

    public function decide(AwardTender $award, int $awardId, bool $approve): void
    {
        $this->authorize('decideAward', $this->tender);
        $row = TenderAward::query()->where('tender_id', $this->tenderId)->findOrFail($awardId);
        $this->run(fn () => $award->decide($row, auth()->user(), $approve, trim((string) ($this->decisionComments[$awardId] ?? '')) ?: null), $approve ? 'tenders.flash.award_approved' : 'tenders.flash.award_rejected');
    }

    public function recordAcceptance(AwardTender $award, int $awardId, bool $accepted): void
    {
        $this->authorize('recordAcceptance', $this->tender);
        $row = TenderAward::query()->where('tender_id', $this->tenderId)->findOrFail($awardId);
        $this->run(fn () => $award->recordAcceptance($row, $accepted, (string) ($this->acceptanceNotes[$awardId] ?? ''), auth()->user()), $accepted ? 'tenders.flash.award_accepted' : 'tenders.flash.award_declined');
    }

    protected function run(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->refresh();

            return;
        }

        session()->flash('status', __($successKey));
        $this->refresh();
        $this->dispatch('tender-updated');
    }

    protected function refresh(): void
    {
        unset($this->tender, $this->bids, $this->awards);

        if ($this->tender->status === TenderStatus::Evaluation && auth()->user()->can('viewBids', $this->tender)) {
            $this->problems = app(TenderEvaluator::class)->evaluate($this->tender);
            unset($this->bids);
        } else {
            $this->problems = [];
        }

        $this->fillInputs();
    }

    /**
     * Public state is sent to the browser: bid-derived inputs only for users who may see bids
     * after opening (BR-T.5).
     */
    protected function fillInputs(): void
    {
        $tender = $this->tender;

        if (! auth()->user()->can('viewBids', $tender)) {
            $this->estimates = $this->scores = $this->selection = [];

            return;
        }

        foreach ($this->bids as $bid) {
            foreach ($bid->lots as $bidLot) {
                foreach ($bidLot->lines as $line) {
                    if ($line->inclusion === ChargeInclusion::NotIncluded) {
                        $this->estimates[$line->id] = $line->estimate_minor !== null ? Money::of($line->estimate_minor, $tender->evaluation_currency)->toMajor() : '';
                    }
                }

                foreach ($tender->criteria->where('method', CriterionMethod::Manual) as $criterion) {
                    $mine = $bidLot->scores->where('criterion_id', $criterion->id)->firstWhere('user_id', auth()->id());
                    $this->scores[$bidLot->id.':'.$criterion->id] = $mine !== null ? (string) $mine->score : '';
                }
            }
        }

        foreach ($tender->lots as $lot) {
            $active = $this->awards->first(fn (TenderAward $a): bool => $a->lot_id === $lot->id && $a->status->isActive());
            $first = TenderBidLot::query()->where('lot_id', $lot->id)->where('rank', 1)->value('id');
            $this->selection[$lot->id] ??= ['bid_lot_id' => (string) ($active?->bid_lot_id ?? $first ?? ''), 'justification' => ''];
        }
    }

    public function render(): View
    {
        return view('livewire.tenders.evaluation', [
            'canSee' => auth()->user()->can('viewBids', $this->tender),
            'currencies' => $this->neededCurrencies(),
            'router' => app(ApprovalRouter::class),
            'pendingAwardStatus' => AwardStatus::Proposed,
        ]);
    }
}
