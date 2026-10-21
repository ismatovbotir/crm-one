<?php

namespace App\Services\Tenders;

use App\Actions\Tenders\SubmitBid;
use App\Enums\BidChannel;
use App\Enums\CounterpartyStatus;
use App\Enums\CriterionMethod;
use App\Enums\TenderType;
use App\Models\Tender;
use App\Models\TenderAward;
use App\Models\TenderBid;
use App\Models\TenderBidLot;
use App\Models\TenderComparison;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Comparison report data (Spec 08 9.1) from the evaluated bids: per lot the normalised table
 * (landed cost, original total, scores, per-kg/m³/pallet, validity, rank, knock-outs, risk flags
 * F1-F17), the charge matrix (I included / E estimate / – n/a / S seller-scope), the
 * recommendation (system rank 1 vs proposed award), savings against the budget, FX ±5 %
 * sensitivity and the evidence index. `core` is deterministic (BR-E.2): the same inputs give the
 * same sha256; generation metadata sits outside it. freeze() stores an immutable snapshot.
 */
class ComparisonBuilder
{
    public function __construct(protected LandedCostCalculator $landed, protected TenderFx $fx, protected ApprovalRouter $router) {}

    /**
     * @return array{core: array<string, mixed>, hash: string}
     */
    public function build(Tender $tender): array
    {
        $tender->loadMissing(['lots.transportSpec', 'lots.items', 'criteria']);
        $bids = TenderEvaluator::currentBids($tender)->load(['source.enterer', 'attachments', 'enteredBy']);
        $awards = TenderAward::query()->where('tender_id', $tender->id)->whereIn('status', ['proposed', 'approved', 'accepted'])->get()->keyBy('lot_id');
        $lots = [];

        foreach ($tender->lots as $lot) {
            $lots[] = $this->lot($tender, $lot, $bids, $awards->get($lot->id));
        }

        $core = [
            'tender' => [
                'number' => $tender->number,
                'title' => $tender->title,
                'type' => $tender->type->value,
                'round' => $tender->round,
                'version' => $tender->version,
                'evaluation_currency' => $tender->evaluation_currency,
                'budget_minor' => $tender->budget_minor,
                'budget_currency' => $tender->budget_currency,
                'bids_due_at' => $tender->bids_due_at?->toIso8601String(),
                'opened_at' => $tender->opened_at?->toIso8601String(),
            ],
            'fx' => (array) $tender->fx_rates,
            'weights' => $tender->criteria->map(fn ($c): array => ['code' => $c->code, 'label' => $c->displayLabel(), 'weight_bp' => $c->weight_bp, 'method' => $c->method->value])->values()->all(),
            'lots' => $lots,
            'evidence' => $bids->map(fn (TenderBid $bid): array => [
                'counterparty' => (string) $bid->counterparty?->name,
                'version' => $bid->version,
                'channel' => $bid->channel->value,
                'received_via' => $bid->source?->received_via,
                'received_at' => $bid->source?->received_at?->toIso8601String(),
                'entered_by' => $bid->enteredBy?->name,
                'submitted_at' => $bid->submitted_at?->toIso8601String(),
                'payload_hash' => $bid->payload_hash,
                'files' => $bid->attachments->map(fn ($a): array => ['name' => $a->original_name, 'sha256' => $a->sha256])->values()->all(),
            ])->values()->all(),
        ];

        return ['core' => $core, 'hash' => hash('sha256', SubmitBid::canonicalJson($core))];
    }

    /**
     * Store the snapshot an award proposal is decided on (immutable once submitted).
     */
    public function freeze(Tender $tender, User $user): TenderComparison
    {
        TenderComparison::query()->where('tender_id', $tender->id)->where('status', TenderComparison::STATUS_SUBMITTED)
            ->get()->each(fn (TenderComparison $c) => $c->forceFill(['status' => TenderComparison::STATUS_SUPERSEDED, 'superseded_at' => now()])->save());

        $built = $this->build($tender);
        $version = (int) TenderComparison::query()->where('tender_id', $tender->id)->where('round_no', $tender->round)->max('version') + 1;

        return TenderComparison::query()->create([
            'tender_id' => $tender->id,
            'round_no' => $tender->round,
            'version' => $version,
            'status' => TenderComparison::STATUS_SUBMITTED,
            'payload' => ['core' => $built['core'], 'meta' => ['generated_by' => $user->name, 'generated_at' => now()->toIso8601String()]],
            'payload_hash' => $built['hash'],
            'generated_by' => $user->id,
            'generated_at' => now(),
            'submitted_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, TenderBid>  $bids
     * @return array<string, mixed>
     */
    protected function lot(Tender $tender, $lot, Collection $bids, ?TenderAward $award): array
    {
        $spec = $lot->transportSpec;
        $rows = [];

        foreach ($bids as $bid) {
            foreach ($bid->lots->where('lot_id', $lot->id) as $bidLot) {
                $rows[] = ['bid' => $bid, 'bidLot' => $bidLot];
            }
        }

        $valid = array_values(array_filter($rows, fn (array $row): bool => $row['bidLot']->rank !== null));
        $landedValues = array_map(fn (array $row): int => (int) $row['bidLot']->landed_cost_minor, $valid);
        sort($landedValues);
        $median = $landedValues === [] ? null : $landedValues[intdiv(count($landedValues), 2)];
        $sensitivity = $this->sensitivity($tender, $valid);
        $rankChangers = collect($sensitivity)->filter(fn (array $s): bool => $s['rank_changes'])->isNotEmpty();
        $systemFirst = collect($valid)->first(fn (array $row): bool => $row['bidLot']->rank === 1);

        $tableRows = array_map(function (array $row) use ($tender, $spec, $median, $rankChangers, $valid): array {
            /** @var TenderBid $bid */
            $bid = $row['bid'];
            $bidLot = $row['bidLot'];
            $landed = $bidLot->landed_cost_minor;
            $components = (array) ($bidLot->breakdown['components'] ?? []);

            return [
                'bid_lot_id' => $bidLot->id,
                'counterparty' => (string) $bid->counterparty?->name,
                'channel' => $bid->channel->value,
                'version' => $bid->version,
                'currency' => $bid->currency,
                'total_minor' => $bidLot->total_minor,
                'landed_minor' => $landed,
                'uses_estimates' => $bidLot->uses_estimates,
                'transit_days' => $bidLot->transit_days,
                'lead_time_days' => $bidLot->lead_time_days,
                'scores' => (array) ($bidLot->breakdown['scores'] ?? []),
                'weighted' => $bidLot->score,
                'rank' => $bidLot->rank,
                'knockouts' => (array) $bidLot->knockout_reasons,
                'valid_until' => $bid->valid_until?->toDateString(),
                'payment_days' => $bid->payment_days,
                'prepayment_bp' => $bid->prepayment_bp,
                'per_kg_minor' => $this->per($landed, $spec?->gross_kg),
                'per_m3_minor' => $this->per($landed, $spec?->volume_m3),
                'per_pallet_minor' => $this->per($landed, $spec?->pallets_count),
                'matrix' => collect($components)->filter(fn (array $c): bool => ! in_array($c['kind'], ['downtime', 'financing', 'carrying', 'item'], true))
                    ->mapWithKeys(fn (array $c): array => [$c['code'] => match ($c['kind']) {
                        'included' => 'I', 'estimate' => 'E', 'seller_scope' => 'S', default => '–',
                    }])->all(),
                'components' => $components,
                'flags' => $this->flags($tender, $bid, $bidLot, $spec, $median, $rankChangers, count($valid)),
            ];
        }, $rows);

        usort($tableRows, fn (array $a, array $b): int => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));

        $proposed = $award !== null ? collect($tableRows)->firstWhere('bid_lot_id', $award->bid_lot_id) : null;
        $budgetUzs = $tender->budget_minor !== null ? $this->fx->toUzs($tender, $tender->budget_minor, (string) ($tender->budget_currency ?? $tender->evaluation_currency)) : null;
        $reference = $proposed['landed_minor'] ?? ($systemFirst ? $systemFirst['bidLot']->landed_cost_minor : null);
        $referenceUzs = $reference !== null ? $this->fx->toUzs($tender, (int) $reference, $tender->evaluation_currency) : null;
        $routing = $reference !== null ? $this->router->awardLevels($tender, (int) $reference, $proposed['rank'] ?? 1, count($valid)) : ['levels' => [], 'reasons' => []];

        return [
            'lot_id' => $lot->id,
            'number' => $lot->number,
            'title' => $lot->title,
            'route' => $spec !== null ? trim(collect([$spec->origin_city, $spec->origin_country])->filter()->implode(', ').' → '.collect([$spec->destination_city, $spec->destination_country])->filter()->implode(', ')) : null,
            'cargo' => $spec !== null ? ['description' => $spec->cargo_description, 'gross_kg' => $spec->gross_kg, 'volume_m3' => $spec->volume_m3, 'value_minor' => $spec->cargo_value_minor, 'currency' => $spec->cargo_currency, 'incoterm' => trim($spec->incoterm.' '.$spec->incoterm_place)] : null,
            'rows' => $tableRows,
            'valid_count' => count($valid),
            'recommendation' => [
                'system_rank1' => $systemFirst ? $systemFirst['bidLot']->id : null,
                'proposed' => $award?->bid_lot_id,
                'justification' => $award?->justification,
                'difference_minor' => $proposed !== null && $systemFirst ? (int) $proposed['landed_minor'] - (int) $systemFirst['bidLot']->landed_cost_minor : null,
            ],
            'savings' => $budgetUzs !== null && $referenceUzs !== null ? ['budget_uzs' => $budgetUzs, 'reference_uzs' => $referenceUzs, 'saving_uzs' => $budgetUzs - $referenceUzs] : null,
            'sensitivity' => $sensitivity,
            'decision' => ['levels' => array_map(fn ($l): string => $l->value, $routing['levels']), 'reasons' => $routing['reasons']],
        ];
    }

    /**
     * FX ±5 % for each foreign bid currency: recomputed landed cost and the cost-criterion part of
     * the weighted score; reports whether the order of the valid bids changes (F14).
     *
     * @param  list<array{bid: TenderBid, bidLot: TenderBidLot}>  $valid
     * @return list<array{currency: string, delta: int, rank_changes: bool, order: list<int>}>
     */
    protected function sensitivity(Tender $tender, array $valid): array
    {
        if (count($valid) < 2) {
            return [];
        }

        $costCriterion = $tender->criteria->first(fn ($c): bool => $c->method === CriterionMethod::AutoLowestCost);
        $baseOrder = array_map(fn (array $row): int => $row['bidLot']->id, collect($valid)->sortBy(fn (array $row) => $row['bidLot']->rank)->values()->all());
        $currencies = collect($valid)->pluck('bid.currency')->unique()->reject(fn (string $c): bool => $c === $tender->evaluation_currency || $c === 'UZS')->values();
        $result = [];

        foreach ($currencies as $currency) {
            foreach ([5, -5] as $delta) {
                $shifted = clone $tender;
                $rates = (array) $tender->fx_rates;

                if (! isset($rates[$currency]['rate'])) {
                    continue;
                }

                $rates[$currency]['rate'] = bcmul((string) $rates[$currency]['rate'], bcdiv((string) (100 + $delta), '100', 4), 6);
                $shifted->setAttribute('fx_rates', $rates);
                $landed = [];

                foreach ($valid as $row) {
                    $landed[$row['bidLot']->id] = (int) $this->landed->calculate($shifted, $row['bid'], $row['bidLot'])['total'];
                }

                $lowest = min($landed);
                $scores = [];

                foreach ($valid as $row) {
                    $id = $row['bidLot']->id;
                    $weighted = (string) $row['bidLot']->score;

                    if ($costCriterion !== null && $landed[$id] > 0) {
                        $old = (string) ($row['bidLot']->breakdown['scores'][$costCriterion->code] ?? '0');
                        $new = bcdiv(bcmul('10', (string) $lowest, 6), (string) $landed[$id], 3);
                        $weighted = bcadd($weighted, bcdiv(bcmul((string) $costCriterion->weight_bp, bcsub($new, $old, 6), 6), '10000', 6), 3);
                    }

                    $scores[$id] = $weighted;
                }

                $order = array_keys($scores);
                usort($order, fn (int $a, int $b): int => bccomp($scores[$b], $scores[$a], 3) ?: ($landed[$a] <=> $landed[$b]));
                $result[] = ['currency' => $currency, 'delta' => $delta, 'rank_changes' => $order !== $baseOrder, 'order' => $order];
            }
        }

        return $result;
    }

    /**
     * Risk flags (Spec 08 9.1; codes printed as text so the report is black-and-white safe).
     *
     * @return list<string>
     */
    protected function flags(Tender $tender, TenderBid $bid, $bidLot, $spec, ?int $median, bool $rankChangers, int $validCount): array
    {
        $flags = [];
        $components = collect((array) ($bidLot->breakdown['components'] ?? []));

        if (filled($bid->exclusions) || filled($bidLot->exceptions)) {
            $flags[] = 'F1';
        }

        if ($bidLot->uses_estimates) {
            $flags[] = 'F2';
        }

        if ($bid->valid_until !== null && $bid->valid_until->toDateString() < CarbonImmutable::now((string) config('tenders.timezone'))->addDays(3)->toDateString()) {
            $flags[] = 'F3';
        }

        if ($bid->prepayment_bp > 0) {
            $flags[] = 'F5';
        }

        if ($tender->type === TenderType::Transport && $spec !== null && $bidLot->transit_days !== null) {
            $late = ($spec->max_transit_days !== null && $bidLot->transit_days > $spec->max_transit_days)
                || ($spec->pickup_from !== null && $spec->delivery_to !== null && $spec->pickup_from->addDays($bidLot->transit_days)->greaterThan($spec->delivery_to));

            if ($late) {
                $flags[] = 'F6';
            }
        }

        if ($median !== null && $bidLot->landed_cost_minor !== null && $bidLot->rank !== null && $bidLot->landed_cost_minor * 10 < $median * 7) {
            $flags[] = 'F7';
        }

        if ($bid->subcontracting) {
            $flags[] = 'F8';
        }

        if ($bid->channel === BidChannel::Employee) {
            $flags[] = 'F9';
        }

        if ($bid->counterparty !== null && $bid->counterparty->status !== CounterpartyStatus::Qualified) {
            $flags[] = 'F10';
        }

        if ($components->contains(fn (array $c): bool => $c['kind'] === 'seller_scope')) {
            $flags[] = 'F13';
        }

        if ($rankChangers && $bidLot->rank !== null) {
            $flags[] = 'F14';
        }

        if ($validCount < 2) {
            $flags[] = 'F15';
        }

        if ($bid->channel === BidChannel::Link && ! $tender->link_verification_required) {
            $flags[] = 'F16';
        }

        if ($bid->needs_confirmation) {
            $flags[] = 'F17';
        }

        return $flags;
    }

    protected function per(?int $landed, mixed $quantity): ?int
    {
        if ($landed === null || $quantity === null || bccomp((string) $quantity, '0', 3) <= 0) {
            return null;
        }

        return LandedCostCalculator::roundHalfUp(bcdiv((string) $landed, (string) $quantity, 6));
    }
}
