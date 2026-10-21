<?php

namespace App\Actions\Tenders;

use App\Enums\ApprovalLevel;
use App\Enums\AwardStatus;
use App\Enums\BidStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Models\NumberSequence;
use App\Models\PurchaseOrder;
use App\Models\StatusTransition;
use App\Models\Tender;
use App\Models\TenderAward;
use App\Models\TenderBidLot;
use App\Models\TenderComparison;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Services\Tenders\ApprovalRouter;
use App\Services\Tenders\ComparisonBuilder;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Services\Tenders\TenderEvaluator;
use App\Support\BusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Award (BR-T.10/T.11): the owner proposes one bid per lot (justification when not rank 1); each
 * award is routed (ApprovalRouter) and approved level by level; when every lot is approved the
 * tender is awarded and bids are marked awarded / not awarded. The winner's acceptance (recorded
 * by the owner with a note, until the portal/link channels exist) contracts the tender; for purchase
 * tenders it creates exactly one purchase order per award (idempotent, unique FK). A decline or an
 * approver's rejection sends the tender back to evaluation for the next rank.
 */
class AwardTender
{
    public function __construct(
        protected TransitionTender $transition,
        protected TenderWorkflow $workflow,
        protected ApprovalRouter $router,
        protected TenderEvaluator $evaluator,
        protected ComparisonBuilder $comparisons,
    ) {}

    /**
     * @param  array<int, array{bid_lot_id: int, justification?: string|null}>  $selection  lot id => choice
     */
    public function propose(Tender $tender, array $selection, User $user): Tender
    {
        if ($tender->status !== TenderStatus::Evaluation) {
            throw new TenderActionException('not_in_evaluation');
        }

        $problems = $this->evaluator->evaluate($tender);

        if ($problems !== []) {
            throw new TenderActionException('evaluation_incomplete', ['problems' => implode('; ', array_map(fn (array $p): string => __('tenders.problems.'.$p['key'], $p['params']), $problems))]);
        }

        $tender->load('lots');

        return DB::transaction(function () use ($tender, $selection, $user): Tender {
            DB::afterCommit(fn () => app(Notifier::class)->tenderAward($tender->fresh(), $user));
            $settled = TenderAward::query()->where('tender_id', $tender->id)->whereIn('status', [AwardStatus::Approved, AwardStatus::Accepted])->pluck('lot_id')->all();

            foreach ($tender->lots as $lot) {
                if (in_array($lot->id, $settled, true)) {
                    continue; // Already awarded and approved; only lots without an active award are (re)proposed.
                }

                $choice = $selection[$lot->id] ?? null;

                if ($choice === null) {
                    throw new TenderActionException('award_every_lot', ['lot' => $lot->number]);
                }

                $bidLot = TenderBidLot::query()->with('bid')->findOrFail((int) $choice['bid_lot_id']);

                if ($bidLot->lot_id !== $lot->id || $bidLot->bid->tender_id !== $tender->id || ! in_array($bidLot->bid->status, [BidStatus::Submitted, BidStatus::Awarded, BidStatus::NotAwarded], true) || $bidLot->rank === null) {
                    throw new TenderActionException('award_bid_invalid');
                }

                $justification = trim((string) ($choice['justification'] ?? ''));

                if ($bidLot->rank !== 1 && $justification === '') {
                    throw new TenderActionException('justification_required', ['lot' => $lot->number]);
                }

                $validBids = TenderBidLot::query()->where('lot_id', $lot->id)->whereNotNull('rank')->count();
                $routing = $this->router->awardLevels($tender, (int) $bidLot->landed_cost_minor, $bidLot->rank, $validBids);

                TenderAward::query()->create([
                    'tender_id' => $tender->id,
                    'lot_id' => $lot->id,
                    'bid_id' => $bidLot->bid_id,
                    'bid_lot_id' => $bidLot->id,
                    'counterparty_id' => $bidLot->bid->counterparty_id,
                    'amount_minor' => $bidLot->total_minor,
                    'currency' => $bidLot->bid->currency,
                    'landed_cost_minor' => (int) $bidLot->landed_cost_minor,
                    'evaluation_currency' => $tender->evaluation_currency,
                    'rank_at_proposal' => $bidLot->rank,
                    'justification' => $justification === '' ? null : $justification,
                    'status' => AwardStatus::Proposed,
                    'required_levels' => array_map(fn (ApprovalLevel $level): string => $level->value, $routing['levels']),
                    'active_lot_id' => $lot->id,
                    'proposed_by' => $user->id,
                    'proposed_at' => now(),
                ]);
            }

            // Spec 08 DEC-6: the decision is taken on a frozen comparison snapshot.
            $snapshot = $this->comparisons->freeze($tender->fresh(), $user);
            TenderAward::query()->where('tender_id', $tender->id)->where('status', AwardStatus::Proposed)->update(['comparison_id' => $snapshot->id]);
            $levels = TenderAward::query()->where('tender_id', $tender->id)->where('status', AwardStatus::Proposed)->pluck('required_levels')
                ->flatMap(fn ($value) => is_array($value) ? $value : (array) json_decode((string) $value, true))->unique()->values()->all();

            return $this->transition->handle($tender, TenderStatus::AwardPendingApproval, $user, 'award_proposed', [
                'decision_level' => in_array('ceo', $levels, true) ? 'ceo' : 'l1',
            ]);
        });
    }

    /**
     * Approve or reject one award at the first pending level the user may decide.
     */
    /**
     * @param  string|null  $password  step-up confirmation (Spec 08 9.4) for tender types in `tenders.decision_step_up`
     */
    public function decide(TenderAward $award, User $user, bool $approve, ?string $comment, ?string $password = null): TenderAward
    {
        return DB::transaction(function () use ($award, $user, $approve, $comment, $password): TenderAward {
            $award = TenderAward::query()->lockForUpdate()->findOrFail($award->id);
            $tender = Tender::query()->lockForUpdate()->findOrFail($award->tender_id);

            if ($tender->status !== TenderStatus::AwardPendingApproval || $award->status !== AwardStatus::Proposed) {
                throw new TenderActionException('award_not_pending');
            }

            $level = collect($award->pendingLevels())->first(fn (ApprovalLevel $level): bool => $this->router->canDecide($user, $tender, $level, $award));

            if ($level === null) {
                throw new TenderActionException('not_approver');
            }

            // Finance co-approves only after the head/CEO level (Spec 08 9.3).
            if ($level === ApprovalLevel::Finance && count($award->pendingLevels()) > 1) {
                throw new TenderActionException('finance_after_ceo');
            }

            if (! $approve && blank($comment)) {
                throw new TenderActionException('comment_required');
            }

            $confirmedVia = null;

            if (in_array($tender->type->value, (array) config('tenders.decision_step_up'), true)) {
                if ($password === null || $user->password === null || ! Hash::check($password, $user->password)) {
                    throw new TenderActionException('step_up_failed');
                }

                $confirmedVia = 'password';
            }

            $snapshot = $award->comparison;
            $this->workflow->decision($tender, $award, 'award', $level, $approve ? 'approve' : 'reject', $comment, $user, [
                'comparison_id' => $snapshot?->id,
                'comparison_hash' => $snapshot?->payload_hash,
                'selected' => [$award->bid_lot_id],
                'confirmed_via' => $confirmedVia,
            ]);

            if (! $approve) {
                TenderAward::query()->where('tender_id', $tender->id)->where('status', AwardStatus::Proposed)->get()
                    ->each(fn (TenderAward $other) => $other->forceFill(['status' => AwardStatus::Rejected, 'active_lot_id' => null])->save());
                $snapshot?->forceFill(['status' => TenderComparison::STATUS_SUPERSEDED, 'superseded_at' => now()])->save();
                $this->transition->handle($tender, TenderStatus::Evaluation, $user, 'award_rejected:'.$comment);

                return $award->fresh();
            }

            if ($award->pendingLevels() === []) {
                $award->forceFill([
                    'status' => AwardStatus::Approved,
                    'approved_at' => now(),
                    'accept_due_at' => now()->addHours(BusinessSettings::int('tenders.accept_within_hours')),
                ])->save();
            }

            $open = TenderAward::query()->where('tender_id', $tender->id)->where('status', AwardStatus::Proposed)->exists();

            if (! $open) {
                $snapshot?->forceFill(['status' => TenderComparison::STATUS_DECIDED])->save();
                $this->markBids($tender);
                $this->transition->handle($tender, TenderStatus::Awarded, $user, null, ['awarded_at' => now()]);
            }

            return $award->fresh();
        });
    }

    /**
     * Winner's answer recorded by the owner (phone/e-mail; link and portal channels: Spec 08/03).
     */
    public function recordAcceptance(TenderAward $award, bool $accepted, string $note, User $user): TenderAward
    {
        return DB::transaction(function () use ($award, $accepted, $note, $user): TenderAward {
            $award = TenderAward::query()->lockForUpdate()->findOrFail($award->id);
            $tender = Tender::query()->lockForUpdate()->findOrFail($award->tender_id);

            if ($tender->status !== TenderStatus::Awarded || $award->status !== AwardStatus::Approved) {
                throw new TenderActionException('award_not_approved');
            }

            if (mb_strlen(trim($note)) < (int) config('tenders.evidence_note_min')) {
                throw new TenderActionException('acceptance_note_required', ['min' => (int) config('tenders.evidence_note_min')]);
            }

            if (! $accepted) {
                $award->forceFill(['status' => AwardStatus::Declined, 'declined_at' => now(), 'decline_reason' => trim($note), 'acceptance_note' => trim($note), 'acceptance_recorded_by' => $user->id, 'active_lot_id' => null])->save();
                // Other lots keep their awards; the declined lot is re-proposed from evaluation (next rank).
                $this->transition->handle($tender, TenderStatus::Evaluation, $user, 'winner_declined:'.trim($note));

                return $award;
            }

            $award->forceFill(['status' => AwardStatus::Accepted, 'accepted_at' => now(), 'acceptance_note' => trim($note), 'acceptance_recorded_by' => $user->id])->save();

            if ($tender->type === TenderType::Purchase) {
                $this->createPurchaseOrder($award, $user);
            }

            $pending = TenderAward::query()->where('tender_id', $tender->id)->where('status', AwardStatus::Approved)->exists();

            if (! $pending) {
                $this->transition->handle($tender, TenderStatus::Contracted, $user, null, ['contracted_at' => now()]);
            }

            return $award;
        });
    }

    /**
     * Purchase order from an accepted purchase award (purchase-tender skill section 6): snapshot of
     * the winning lines; exactly one per award (AC-25) — a second call returns the existing one.
     */
    public function createPurchaseOrder(TenderAward $award, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($award, $user): PurchaseOrder {
            $award = TenderAward::query()->lockForUpdate()->findOrFail($award->id);

            if ($existing = PurchaseOrder::query()->where('tender_award_id', $award->id)->first()) {
                return $existing;
            }

            if ($award->status !== AwardStatus::Accepted) {
                throw new TenderActionException('award_not_accepted');
            }

            $tender = $award->tender;

            if ($tender->type !== TenderType::Purchase) {
                throw new TenderActionException('wrong_type');
            }

            $bidLot = TenderBidLot::query()->with(['lines.tenderItem'])->findOrFail($award->bid_lot_id);
            $year = (int) CarbonImmutable::now((string) config('tenders.timezone'))->year;

            $po = PurchaseOrder::query()->create([
                'number' => NumberSequence::format('PO', $year, NumberSequence::next('purchase_order', $year)),
                'source' => 'tender_award',
                'tender_award_id' => $award->id,
                'supplier_id' => $award->counterparty_id,
                'order_id' => $tender->sources()->where('source_type', 'order')->value('source_id'),
                'owner_id' => $tender->owner_id,
                'department_id' => $tender->department_id,
                'status' => PurchaseOrderStatus::Draft,
                'currency' => $award->currency,
                'subtotal_minor' => $award->amount_minor,
                'incoterm' => $tender->incoterm,
                'incoterm_place' => $tender->incoterm_place,
                'destination_warehouse_id' => $tender->delivery_warehouse_id,
                'required_delivery_date' => $bidLot->lines->map(fn ($line) => $line->tenderItem?->required_by)->filter()->min()?->toDateString(),
                'payment_terms' => $tender->payment_terms,
                'payment_days' => $award->bid->payment_days,
                'prepayment_bp' => $award->bid->prepayment_bp,
            ]);

            foreach ($bidLot->lines->values() as $index => $line) {
                $item = $line->tenderItem;
                $po->lines()->create([
                    'position' => $index + 1,
                    'item_id' => $item?->item_id,
                    'tender_item_id' => $item?->id,
                    'sku' => $item?->sku,
                    'name' => (string) ($item?->name ?? $line->description),
                    'unit_code' => $item?->unit_code,
                    'unit_precision' => (int) ($item?->unit_precision ?? 3),
                    'quantity' => (string) $line->quantity,
                    'unit_price_minor' => (int) $line->unit_price_minor,
                    'line_total_minor' => $line->amount_minor,
                    'hs_code' => $item?->hs_code,
                    'offered_brand' => $line->offered_brand,
                    'offered_model' => $line->offered_model,
                    'required_by' => $item?->required_by?->toDateString(),
                ]);
            }

            StatusTransition::query()->create([
                'subject_type' => $po->getMorphClass(),
                'subject_id' => $po->id,
                'from_status' => null,
                'to_status' => PurchaseOrderStatus::Draft->value,
                'user_id' => $user->id,
                'reason' => 'from_award:'.$tender->number,
                'created_at' => now(),
            ]);

            return $po;
        });
    }

    /** Awarded bids vs the rest once every lot is approved. */
    protected function markBids(Tender $tender): void
    {
        $awarded = TenderAward::query()->where('tender_id', $tender->id)->whereIn('status', [AwardStatus::Approved, AwardStatus::Accepted])->pluck('bid_id')->all();

        foreach (TenderEvaluator::currentBids($tender) as $bid) {
            if ($bid->status === BidStatus::Disqualified) {
                continue;
            }

            $bid->forceFill(['status' => in_array($bid->id, $awarded, true) ? BidStatus::Awarded : BidStatus::NotAwarded])->save();
        }
    }
}
