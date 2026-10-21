<?php

namespace App\Actions\Tenders;

use App\Enums\BidStatus;
use App\Enums\ChargeInclusion;
use App\Enums\CriterionMethod;
use App\Enums\TenderStatus;
use App\Models\Tender;
use App\Models\TenderBid;
use App\Models\TenderBidLine;
use App\Models\TenderBidLot;
use App\Models\TenderCriterion;
use App\Models\User;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Services\Tenders\TenderAccess;
use App\Services\Tenders\TenderEvaluator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Evaluation inputs after opening (BR-T.8/T.9): FX rates frozen by finance (UZS per unit, CBU rate
 * of the closing date), the owner's estimates for not-included charges, knock-out
 * disqualification with a reason, committee scores of manual criteria (after the conflict-of-
 * interest declaration), and recomputation. Changing inputs while an award awaits approval is
 * refused (the proposal would be stale).
 */
class EvaluateTender
{
    public function __construct(protected TenderEvaluator $evaluator) {}

    /**
     * @param  array<string, string>  $rates  currency => UZS per unit (decimal string)
     */
    public function setFx(Tender $tender, array $rates, string $rateDate, User $user): Tender
    {
        $this->assertEvaluating($tender, allowClosed: true);
        $stored = (array) $tender->fx_rates;

        foreach ($rates as $currency => $rate) {
            $currency = strtoupper((string) $currency);
            $rate = str_replace([' ', ','], ['', '.'], trim((string) $rate));

            if ($currency === 'UZS' || $rate === '') {
                continue;
            }

            if (! in_array($currency, config('tenders.currencies'), true) || ! is_numeric($rate) || bccomp($rate, '0', 6) <= 0) {
                throw new TenderActionException('fx_invalid', ['currency' => $currency]);
            }

            $stored[$currency] = ['rate' => bcadd($rate, '0', 6), 'date' => CarbonImmutable::parse($rateDate)->toDateString(), 'source' => 'CBU'];
        }

        $tender->forceFill(['fx_rates' => $stored, 'fx_set_by' => $user->id, 'fx_set_at' => now()])->save();
        $this->recompute($tender);

        return $tender;
    }

    public function setEstimate(TenderBidLine $line, ?string $amountMajor, User $user): void
    {
        $bidLot = TenderBidLot::query()->with('bid.tender')->findOrFail($line->bid_lot_id);
        $tender = $bidLot->bid->tender;
        $this->assertEvaluating($tender);

        if ($line->inclusion !== ChargeInclusion::NotIncluded) {
            throw new TenderActionException('estimate_only_not_included');
        }

        try {
            $minor = blank($amountMajor) ? null : Money::fromMajor((string) $amountMajor, $tender->evaluation_currency)->amount;
        } catch (InvalidArgumentException) {
            throw new TenderActionException('amount_invalid', ['value' => (string) $amountMajor]);
        }

        if ($minor !== null && $minor < 0) {
            throw new TenderActionException('amount_invalid', ['value' => (string) $amountMajor]);
        }

        $line->forceFill(['estimate_minor' => $minor, 'estimate_by' => $user->id])->save();
        $tender->logActivity('estimate_set', ['counterparty' => $bidLot->bid->counterparty?->name, 'charge' => $line->charge_code?->value]);
        $this->recompute($tender);
    }

    public function disqualify(TenderBid $bid, string $reason, User $user): void
    {
        $tender = $bid->tender;
        $this->assertEvaluating($tender);

        if ($bid->status !== BidStatus::Submitted) {
            throw new TenderActionException('bid_not_current');
        }

        if (trim($reason) === '') {
            throw new TenderActionException('reason_required');
        }

        $bid->forceFill(['status' => BidStatus::Disqualified, 'disqualified_at' => now(), 'disqualified_by' => $user->id, 'disqualification_reason' => trim($reason)])->save();
        $tender->logActivity('bid_disqualified', ['counterparty' => $bid->counterparty?->name, 'reason' => trim($reason)]);
        $this->recompute($tender);
    }

    public function requalify(TenderBid $bid, User $user): void
    {
        $tender = $bid->tender;
        $this->assertEvaluating($tender);

        if ($bid->status !== BidStatus::Disqualified) {
            throw new TenderActionException('bid_not_disqualified');
        }

        $bid->forceFill(['status' => BidStatus::Submitted, 'disqualified_at' => null, 'disqualified_by' => null, 'disqualification_reason' => null])->save();
        $tender->logActivity('bid_requalified', ['counterparty' => $bid->counterparty?->name]);
        $this->recompute($tender);
    }

    public function score(TenderBidLot $bidLot, TenderCriterion $criterion, string $score, ?string $comment, User $user): void
    {
        $tender = $bidLot->bid->tender;
        $this->assertEvaluating($tender);

        if ($criterion->tender_id !== $tender->id || $criterion->method !== CriterionMethod::Manual) {
            throw new TenderActionException('criterion_not_manual');
        }

        // Committee members score after their conflict-of-interest declaration; without a
        // committee the tender owner side is the evaluator (skill section 5 "evaluator average").
        $member = $tender->committee()->where('user_id', $user->id)->first();
        $ownerSide = ! $tender->committee()->exists() && TenderAccess::canManage($user, $tender);

        if (! $ownerSide && ($member === null || $member->coi_declared_at === null)) {
            throw new TenderActionException('coi_required');
        }

        $value = str_replace(',', '.', trim($score));

        if (! is_numeric($value) || bccomp($value, '0', 2) < 0 || bccomp($value, '10', 2) > 0) {
            throw new TenderActionException('score_range');
        }

        DB::transaction(function () use ($bidLot, $criterion, $value, $comment, $user, $tender): void {
            $bidLot->scores()->updateOrCreate(
                ['criterion_id' => $criterion->id, 'user_id' => $user->id],
                ['score' => bcadd($value, '0', 2), 'comment' => $comment],
            );
            $this->recompute($tender);
        });
    }

    /**
     * @return list<array{key: string, params: array<string, string|int>}>
     */
    public function recompute(Tender $tender): array
    {
        return $this->evaluator->evaluate($tender->fresh());
    }

    protected function assertEvaluating(Tender $tender, bool $allowClosed = false): void
    {
        $allowed = $allowClosed ? [TenderStatus::Closed, TenderStatus::Evaluation] : [TenderStatus::Evaluation];

        if (! in_array($tender->status, $allowed, true)) {
            throw new TenderActionException('not_in_evaluation');
        }
    }
}
