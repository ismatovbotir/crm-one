<?php

namespace App\Actions\Tenders;

use App\Enums\ApprovalLevel;
use App\Enums\BidStatus;
use App\Enums\InvitationStatus;
use App\Enums\TenderStatus;
use App\Models\Counterparty;
use App\Models\Tender;
use App\Models\TenderAward;
use App\Models\TenderBid;
use App\Models\TenderDecision;
use App\Models\TenderQuestion;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Services\Tenders\ApprovalRouter;
use App\Services\Tenders\CompletenessChecker;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Support\BusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tender lifecycle steps (Spec 03 6.1, BR-T.2/T.3/T.6): submit for approval (0 blockers, warnings
 * acknowledged), publication approval (ApprovalRouter), publish, Q&A, addenda, close and open
 * bids at the deadline (scheduler, idempotent), execution, completion, cancel and re-tender.
 */
class TenderWorkflow
{
    public function __construct(
        protected TransitionTender $transition,
        protected CompletenessChecker $checker,
        protected ApprovalRouter $router,
    ) {}

    /**
     * Run the completeness check and store the result on the tender (panel, AC-17).
     *
     * @return list<array{code: string, level: string, key: string, params: array<string, string|int>, lot: int|null}>
     */
    public function runCheck(Tender $tender): array
    {
        $issues = $this->checker->check($tender);
        $tender->forceFill(['completeness' => ['checked_at' => now()->toIso8601String(), 'issues' => $issues]])->saveQuietly();

        return $issues;
    }

    /**
     * @param  array<string, string>  $acknowledgements  issue key => comment
     */
    public function submitForApproval(Tender $tender, User $user, array $acknowledgements = []): Tender
    {
        if ($tender->status !== TenderStatus::Draft) {
            throw new TenderActionException('not_draft');
        }

        $issues = $this->runCheck($tender);

        if (($blockers = CompletenessChecker::blockers($issues)) > 0) {
            throw new TenderActionException('has_blockers', ['count' => $blockers]);
        }

        $acknowledged = array_filter(array_map(fn ($comment): string => trim((string) $comment), [...(array) $tender->acknowledged_warnings, ...$acknowledgements]));
        $missing = CompletenessChecker::unacknowledged($issues, $acknowledged);

        if ($missing !== []) {
            throw new TenderActionException('warnings_unacknowledged', ['codes' => implode(', ', $missing)]);
        }

        $warningKeys = array_map(fn (array $issue): string => CompletenessChecker::issueKey($issue), array_filter($issues, fn (array $issue): bool => $issue['level'] === CompletenessChecker::WARNING));
        $kept = array_intersect_key($acknowledged, array_flip($warningKeys));
        $reason = $kept === [] ? null : 'warnings_acknowledged:'.collect($kept)->map(fn (string $comment, string $key): string => $key.' — '.$comment)->implode('; ');

        $tender = $this->transition->handle($tender, TenderStatus::PendingApproval, $user, $reason, [
            'acknowledged_warnings' => $kept,
            'submitted_at' => now(),
        ]);
        DB::afterCommit(fn () => app(Notifier::class)->tenderPublication($tender, $user));

        return $tender;
    }

    public function decidePublication(Tender $tender, User $user, bool $approve, ?string $comment): Tender
    {
        if ($tender->status !== TenderStatus::PendingApproval) {
            throw new TenderActionException('not_pending_approval');
        }

        $levels = $this->router->publishLevels($tender)['levels'];
        $level = collect($levels)->first(fn (ApprovalLevel $level): bool => $this->router->canDecide($user, $tender, $level));

        if ($level === null) {
            throw new TenderActionException('not_approver');
        }

        if (! $approve && blank($comment)) {
            throw new TenderActionException('comment_required');
        }

        return DB::transaction(function () use ($tender, $user, $approve, $comment, $level): Tender {
            $this->decision($tender, null, 'publish', $level, $approve ? 'approve' : 'reject', $comment, $user);

            return $approve
                ? $this->transition->handle($tender, TenderStatus::Approved, $user, $comment, ['approved_at' => now()])
                : $this->transition->handle($tender, TenderStatus::Draft, $user, 'rejected:'.$comment);
        });
    }

    public function publish(Tender $tender, User $user): Tender
    {
        if ($tender->status !== TenderStatus::Approved) {
            throw new TenderActionException('not_approved');
        }

        $issues = $this->runCheck($tender);

        if (($blockers = CompletenessChecker::blockers($issues)) > 0) {
            throw new TenderActionException('has_blockers', ['count' => $blockers]);
        }

        if (! $tender->invitations()->exists()) {
            throw new TenderActionException('no_invitees');
        }

        return DB::transaction(function () use ($tender, $user): Tender {
            $tender->invitations()->whereNull('invited_at')->update(['invited_at' => now()]);

            return $this->transition->handle($tender, TenderStatus::Published, $user, null, ['published_at' => now()]);
        });
    }

    public function recordQuestion(Tender $tender, ?Counterparty $counterparty, string $question, ?string $receivedVia, ?User $user): TenderQuestion
    {
        if ($tender->status !== TenderStatus::Published || ($tender->clarification_deadline_at !== null && $tender->clarification_deadline_at->isPast())) {
            throw new TenderActionException('clarification_closed');
        }

        if (trim($question) === '') {
            throw new TenderActionException('question_required');
        }

        return $tender->questions()->create([
            'counterparty_id' => $counterparty?->id,
            'asked_by' => $user?->id,
            'received_via' => $receivedVia,
            'question' => trim($question),
        ]);
    }

    /** Answers are published to every invitee without the asker's identity (BR-T.5). */
    public function answerQuestion(TenderQuestion $question, string $answer, User $user): TenderQuestion
    {
        if (trim($answer) === '') {
            throw new TenderActionException('answer_required');
        }

        $question->update(['answer' => trim($answer), 'answered_by' => $user->id, 'answered_at' => now(), 'published_at' => now()]);
        Tender::query()->findOrFail($question->tender_id)->logActivity('question_answered');

        return $question;
    }

    /**
     * Addendum (BR-T.3): version + 1; the deadline moves to the requested date and is extended to
     * now + 48 h when less remains; submitted bids are flagged "needs confirmation".
     */
    public function addAddendum(Tender $tender, User $user, string $summary, ?string $changes, ?CarbonImmutable $newDue): Tender
    {
        return DB::transaction(function () use ($tender, $user, $summary, $changes, $newDue): Tender {
            $tender = Tender::query()->lockForUpdate()->findOrFail($tender->id);

            if ($tender->status !== TenderStatus::Published || $tender->bids_due_at === null || $tender->bids_due_at->isPast()) {
                throw new TenderActionException('addendum_not_allowed');
            }

            if (trim($summary) === '') {
                throw new TenderActionException('summary_required');
            }

            if ($newDue !== null && $newDue->lessThanOrEqualTo($tender->bids_due_at)) {
                throw new TenderActionException('addendum_due_earlier');
            }

            $previous = $tender->bids_due_at;
            $due = $newDue ?? $previous;
            $minimum = CarbonImmutable::now()->addHours(BusinessSettings::int('tenders.addendum_min_hours'))->startOfMinute();

            if ($due->lessThan($minimum)) {
                $due = $minimum;
            }

            $version = $tender->version + 1;
            $tender->addenda()->create([
                'version' => $version,
                'summary' => trim($summary),
                'changes' => $changes,
                'previous_bids_due_at' => $previous,
                'new_bids_due_at' => $due->equalTo($previous) ? null : $due,
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            $tender->forceFill(['version' => $version, 'bids_due_at' => $due])->save();
            TenderBid::query()->where('tender_id', $tender->id)->where('status', BidStatus::Submitted)->update(['needs_confirmation' => true]);
            $tender->invitations()->where('status', InvitationStatus::Submitted)->update(['needs_addendum_confirmation' => true]);
            $tender->logActivity('addendum_issued', ['version' => $version, 'bids_due_at' => $due->toIso8601String()]);

            return $tender;
        });
    }

    /**
     * Close at the deadline and open the bids (scheduler or owner; idempotent): opened_at set,
     * invitees without a bid marked no_response, then evaluation when at least one bid exists.
     */
    public function close(Tender $tender, ?User $user = null): Tender
    {
        return DB::transaction(function () use ($tender, $user): Tender {
            $tender = Tender::query()->lockForUpdate()->findOrFail($tender->id);

            if ($tender->status !== TenderStatus::Published) {
                return $tender;
            }

            if ($tender->bids_due_at === null || $tender->bids_due_at->isFuture()) {
                throw new TenderActionException('deadline_not_reached');
            }

            $this->transition->handle($tender, TenderStatus::Closed, $user, 'deadline_reached', ['opened_at' => now()]);

            $bidders = TenderBid::query()->where('tender_id', $tender->id)->where('round_no', $tender->round)->where('status', BidStatus::Submitted)->pluck('invitation_id')->all();
            $tender->invitations()->whereNotIn('id', $bidders)->whereNotIn('status', [InvitationStatus::Declined->value])->update(['status' => InvitationStatus::NoResponse]);
            $tender->logActivity('bids_opened', ['bids' => count($bidders)]);

            if ($bidders !== []) {
                $this->transition->handle($tender, TenderStatus::Evaluation, $user, 'bids_received');
            }

            return $tender;
        });
    }

    /**
     * Scheduler entry point: close every published tender whose deadline passed.
     */
    public function closeDue(): int
    {
        $count = 0;

        Tender::query()->where('status', TenderStatus::Published)->where('bids_due_at', '<=', now())->orderBy('id')->each(function (Tender $tender) use (&$count): void {
            $this->close($tender);
            $count++;
        });

        return $count;
    }

    public function startExecution(Tender $tender, User $user): Tender
    {
        return $this->transition->handle($tender, TenderStatus::InExecution, $user);
    }

    public function complete(Tender $tender, User $user): Tender
    {
        return $this->transition->handle($tender, TenderStatus::Completed, $user, null, ['completed_at' => now()]);
    }

    public function cancel(Tender $tender, User $user, string $reason): Tender
    {
        if (trim($reason) === '') {
            throw new TenderActionException('reason_required');
        }

        return DB::transaction(function () use ($tender, $user, $reason): Tender {
            TenderAward::query()->where('tender_id', $tender->id)->whereNotNull('active_lot_id')->get()
                ->each(fn (TenderAward $award) => $award->forceFill(['status' => 'rejected', 'active_lot_id' => null])->save());

            return $this->transition->handle($tender, TenderStatus::Cancelled, $user, trim($reason), ['cancelled_at' => now(), 'cancel_reason' => trim($reason)]);
        });
    }

    /**
     * Re-tender (Spec 03 6.1): a new draft copying header, lots, items/specs, criteria,
     * requirements and invitees; `parent_tender_id`, round + 1. Bids are never copied.
     */
    public function retender(Tender $tender, User $user): Tender
    {
        if ($tender->status !== TenderStatus::Cancelled) {
            throw new TenderActionException('retender_needs_cancelled');
        }

        return DB::transaction(function () use ($tender, $user): Tender {
            $tender->loadMissing(['lots.items', 'lots.transportSpec', 'criteria', 'requirements', 'invitations']);
            $copy = app(ManageTender::class)->create($tender->type, ['title' => $tender->title, 'method' => $tender->method->value, 'kind' => $tender->kind->value], $user);
            $copy->forceFill([
                ...array_intersect_key($tender->getAttributes(), array_flip(array_diff(ManageTender::HEADER, ['clarification_deadline_at', 'bids_due_at']))),
                'parent_tender_id' => $tender->id,
                'round' => $tender->round + 1,
            ])->save();
            $copy->lots()->each(fn ($lot) => $lot->delete());
            $copy->criteria()->delete();

            foreach ($tender->lots as $lot) {
                $newLot = $copy->lots()->create(['number' => $lot->number, 'title' => $lot->title, 'description' => $lot->description]);

                foreach ($lot->items as $item) {
                    $newLot->items()->create(array_diff_key($item->getAttributes(), array_flip(['id', 'lot_id', 'created_at', 'updated_at'])));
                }

                if ($lot->transportSpec !== null) {
                    $newLot->transportSpec()->create(array_diff_key($lot->transportSpec->getAttributes(), array_flip(['id', 'lot_id', 'created_at', 'updated_at'])));
                }
            }

            foreach ($tender->criteria as $criterion) {
                $copy->criteria()->create($criterion->only(['code', 'label', 'weight_bp', 'method', 'position']));
            }

            foreach ($tender->requirements as $requirement) {
                $copy->requirements()->create($requirement->only(['text', 'is_knockout']));
            }

            foreach ($tender->invitations()->with('counterparty')->get() as $invitation) {
                if ($invitation->counterparty?->status->canBid()) {
                    $copy->invitations()->create([...$invitation->only(['counterparty_id', 'channel_preference', 'contact_email', 'notice_locale']), 'invited_by' => $user->id, 'status' => InvitationStatus::Invited]);
                }
            }

            $tender->logActivity('retendered', ['number' => $copy->number]);

            return $copy;
        });
    }

    /**
     * @param  array{comparison_id?: int|null, comparison_hash?: string|null, selected?: list<int>|null, confirmed_via?: string|null}  $extra
     */
    public function decision(Tender $tender, ?TenderAward $award, string $subject, ApprovalLevel $level, string $decision, ?string $comment, User $user, array $extra = []): TenderDecision
    {
        return TenderDecision::query()->create([
            ...$extra,
            'tender_id' => $tender->id,
            'award_id' => $award?->id,
            'subject' => $subject,
            'level' => $level,
            'decision' => $decision,
            'comment' => $comment,
            'decided_by' => $user->id,
            'ip' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }
}
