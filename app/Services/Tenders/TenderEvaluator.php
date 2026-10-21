<?php

namespace App\Services\Tenders;

use App\Enums\AwardStatus;
use App\Enums\BidStatus;
use App\Enums\CriterionMethod;
use App\Enums\TenderType;
use App\Models\Tender;
use App\Models\TenderAward;
use App\Models\TenderBid;
use App\Models\TenderBidLot;
use App\Models\TenderCriterion;
use App\Models\TenderLot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Evaluation of opened bids per lot (BR-T.8/T.9, transport-tender skill section 5): knock-outs,
 * landed cost, criterion scores 0-10, weighted score = Σ weight × score / 10000, rank (ties:
 * lower landed cost, then earlier submission). Results are written to the bid lots on every run
 * (always recomputed server-side from lines, Spec 08 BR-B.6). Returns the problems that block an
 * award proposal: missing FX rates, missing estimates, manual criteria without committee scores.
 */
class TenderEvaluator
{
    public function __construct(protected LandedCostCalculator $landed) {}

    /**
     * Bids taking part in the current round: the latest version per counterparty.
     *
     * @return Collection<int, TenderBid>
     */
    public static function currentBids(Tender $tender): Collection
    {
        return TenderBid::query()
            ->where('tender_id', $tender->id)
            ->where('round_no', $tender->round)
            ->whereIn('status', [BidStatus::Submitted, BidStatus::Awarded, BidStatus::NotAwarded, BidStatus::Disqualified])
            ->with(['counterparty', 'lots.lines', 'lots.lot.transportSpec', 'lots.lot.items', 'lots.scores'])
            ->orderBy('submitted_at')
            ->get();
    }

    /**
     * @return list<array{key: string, params: array<string, string|int>}>
     */
    public function evaluate(Tender $tender): array
    {
        $tender->loadMissing(['lots.items', 'lots.transportSpec', 'criteria', 'committee']);
        $bids = self::currentBids($tender);
        $problems = [];

        DB::transaction(function () use ($tender, $bids, &$problems): void {
            foreach ($tender->lots as $lot) {
                $problems = [...$problems, ...$this->evaluateLot($tender, $lot, $bids)];
            }
        });

        return array_values(array_unique($problems, SORT_REGULAR));
    }

    /**
     * @param  Collection<int, TenderBid>  $bids
     * @return list<array{key: string, params: array<string, string|int>}>
     */
    protected function evaluateLot(Tender $tender, TenderLot $lot, Collection $bids): array
    {
        $problems = [];
        $rows = [];

        foreach ($bids as $bid) {
            foreach ($bid->lots->where('lot_id', $lot->id) as $bidLot) {
                $result = $this->landed->calculate($tender, $bid, $bidLot);
                $knockouts = $this->knockouts($tender, $lot, $bid, $bidLot);

                foreach ($result['missing_fx'] as $currency) {
                    $problems[] = ['key' => 'fx_missing', 'params' => ['currency' => $currency]];
                }

                if ($knockouts === []) {
                    foreach ($result['missing_estimates'] as $code) {
                        $problems[] = ['key' => 'estimate_missing', 'params' => ['counterparty' => (string) $bid->counterparty?->name, 'charge' => __('tenders.charges.'.$code)]];
                    }
                }

                $rows[] = ['bid' => $bid, 'bidLot' => $bidLot, 'landed' => $result, 'knockouts' => $knockouts];
            }
        }

        $valid = array_values(array_filter($rows, fn (array $row): bool => $row['knockouts'] === [] && $row['landed']['total'] !== null));
        $lowest = $valid === [] ? null : min(array_map(fn (array $row): int => $row['landed']['total'], $valid));
        $days = fn (TenderBidLot $bidLot): int => (int) ($tender->type === TenderType::Transport ? $bidLot->transit_days : $bidLot->lead_time_days);
        $fastest = $valid === [] ? null : min(array_map(fn (array $row): int => $days($row['bidLot']), $valid));

        foreach ($rows as $index => $row) {
            $scores = [];
            $weighted = null;

            if (in_array($row, $valid, true)) {
                $weighted = '0';

                foreach ($tender->criteria as $criterion) {
                    $score = $this->score($tender, $criterion, $row, (int) $lowest, (int) $fastest, $days($row['bidLot']));

                    if ($score === null) {
                        $problems[] = ['key' => 'scores_missing', 'params' => ['criterion' => $criterion->displayLabel()]];
                        $score = '0';
                    }

                    $scores[$criterion->code] = $score;
                    $weighted = bcadd($weighted, bcdiv(bcmul((string) $criterion->weight_bp, $score, 6), '10000', 6), 6);
                }

                $weighted = bcadd($weighted, '0', 3);
            }

            $rows[$index]['scores'] = $scores;
            $rows[$index]['weighted'] = $weighted;
        }

        $ranked = array_values(array_filter($rows, fn (array $row): bool => $row['weighted'] !== null));
        usort($ranked, function (array $a, array $b): int {
            return bccomp($b['weighted'], $a['weighted'], 3)
                ?: ($a['landed']['total'] <=> $b['landed']['total'])
                ?: ($a['bid']->submitted_at?->getTimestamp() <=> $b['bid']->submitted_at?->getTimestamp());
        });
        $ranks = [];

        foreach ($ranked as $position => $row) {
            $ranks[$row['bidLot']->id] = $position + 1;
        }

        foreach ($rows as $row) {
            $row['bidLot']->forceFill([
                'landed_cost_minor' => $row['landed']['total'],
                'uses_estimates' => $row['landed']['uses_estimates'],
                'score' => $row['weighted'],
                'rank' => $ranks[$row['bidLot']->id] ?? null,
                'knockout_reasons' => $row['knockouts'] === [] ? null : $row['knockouts'],
                'breakdown' => ['currency' => $tender->evaluation_currency, 'components' => $row['landed']['components'], 'scores' => $row['scores']],
            ])->save();
        }

        return $problems;
    }

    /**
     * Pass/fail checks before scoring (skill section 5, Spec 08 7.4). Codes: tenders.knockouts.*.
     *
     * @return list<string>
     */
    protected function knockouts(Tender $tender, TenderLot $lot, TenderBid $bid, TenderBidLot $bidLot): array
    {
        $reasons = [];

        if ($bid->status === BidStatus::Disqualified) {
            $reasons[] = 'disqualified';
        }

        if ($bid->counterparty !== null && ! $bid->counterparty->status->canBid()) {
            $reasons[] = 'counterparty_status';
        }

        if ($tender->bids_due_at !== null) {
            $required = $tender->localTime($tender->bids_due_at)->addDays($tender->bid_validity_days)->toDateString();

            if ($bid->valid_until === null || $bid->valid_until->toDateString() < $required) {
                $reasons[] = 'validity';
            }
        }

        if ($tender->type === TenderType::Transport) {
            if ($bidLot->transit_days === null || $bidLot->downtime_rate_minor_per_day === null) {
                $reasons[] = 'mandatory_fields';
            }
        } else {
            $quoted = $bidLot->lines->whereNotNull('tender_item_id')->pluck('tender_item_id')->all();

            if ($lot->items->pluck('id')->diff($quoted)->isNotEmpty() || $bidLot->lead_time_days === null) {
                $reasons[] = 'incomplete';
            }

            if ($bidLot->lines->contains(fn ($line): bool => ! $line->compliant)) {
                $reasons[] = 'non_compliant';
            }
        }

        if (! $bid->conditions_accepted) {
            $reasons[] = 'conditions';
        }

        if (TenderAward::query()->where('bid_lot_id', $bidLot->id)->where('status', AwardStatus::Declined)->exists()) {
            $reasons[] = 'award_declined';
        }

        return $reasons;
    }

    /**
     * @param  array{bid: TenderBid, bidLot: TenderBidLot, landed: array{total: int|null}}  $row
     */
    protected function score(Tender $tender, TenderCriterion $criterion, array $row, int $lowest, int $fastest, int $days): ?string
    {
        $bid = $row['bid'];
        $total = (int) $row['landed']['total'];

        return match ($criterion->method) {
            CriterionMethod::AutoLowestCost => $total <= 0 ? '10.000' : bcdiv(bcmul('10', (string) $lowest, 6), (string) $total, 3),
            CriterionMethod::AutoFastest => $days <= 0 ? '10.000' : bcdiv(bcmul('10', (string) $fastest, 6), (string) $days, 3),
            CriterionMethod::AutoPayment => $this->paymentScore($tender, $bid),
            CriterionMethod::Rating => $bid->counterparty?->rating !== null ? bcmul((string) $bid->counterparty->rating, '2', 3) : '5.000',
            CriterionMethod::Manual => $this->manualScore($row['bidLot'], $criterion),
        };
    }

    protected function paymentScore(Tender $tender, TenderBid $bid): string
    {
        $requested = (int) $tender->payment_days;
        $offered = (int) $bid->payment_days;
        $factor = $requested > 0 ? bcdiv((string) min($offered, $requested), (string) $requested, 6) : '1';
        $prepayment = bcsub('1', bcdiv((string) min(10000, $bid->prepayment_bp), '10000', 6), 6);

        return bcmul(bcmul('10', $factor, 6), $prepayment, 3);
    }

    protected function manualScore(TenderBidLot $bidLot, TenderCriterion $criterion): ?string
    {
        $scores = $bidLot->scores->where('criterion_id', $criterion->id);

        if ($scores->isEmpty()) {
            return null;
        }

        $sum = $scores->reduce(fn (string $carry, $score): string => bcadd($carry, (string) $score->score, 2), '0');

        return bcdiv($sum, (string) $scores->count(), 3);
    }
}
