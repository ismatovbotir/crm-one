<?php

namespace App\Actions\StockOff;

use App\Authorization\PermissionMap;
use App\Enums\StockOffStatus;
use App\Models\StatusTransition;
use App\Models\StockOffAct;
use App\Models\StockOffLineSerial;
use App\Models\User;
use App\Services\Stock\Exceptions\StockOffException;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * The only place a stock-off act's status changes (Spec 06 section 27.3). Who moves what:
 * - submit, rework a rejected act, cancel a draft/rejected act: stock staff (`stock_off.create`);
 * - approve / reject: the warehouse head or a manager (`stock_off.approve`), or from the value
 *   limit the CEO / finance (`stock_off.approve_high`); never the creator or the submitter (SoD);
 * - cancel a submitted or approved act: an approver;
 * - post: stock staff (`stock_off.post`), see PostStockOffAct.
 * Reject and cancel need a reason. Every move is written to `status_transitions`.
 */
class TransitionStockOffAct
{
    public function __construct(protected PostStockOffAct $post) {}

    public static function ability(StockOffAct $act, StockOffStatus $to): ?string
    {
        $from = $act->status;

        if (! $from->canTransitionTo($to)) {
            return null;
        }

        $approve = $act->needsHighApproval() ? 'stock_off.approve_high' : 'stock_off.approve';

        return match ($to) {
            StockOffStatus::Submitted, StockOffStatus::Draft => 'stock_off.create',
            StockOffStatus::Approved, StockOffStatus::Rejected => $approve,
            StockOffStatus::Posted => 'stock_off.post',
            StockOffStatus::Cancelled => in_array($from, [StockOffStatus::Draft, StockOffStatus::Rejected], true) ? 'stock_off.create' : 'stock_off.approve',
        };
    }

    public static function allows(User $user, StockOffAct $act, StockOffStatus $to): bool
    {
        $ability = self::ability($act, $to);

        if ($ability === null || ! PermissionMap::allows($user, $ability)) {
            return false;
        }

        // SoD: the maker never checks their own act.
        return ! in_array($to, [StockOffStatus::Approved, StockOffStatus::Rejected], true)
            || ! in_array($user->id, array_filter([$act->created_by, $act->submitted_by]), true);
    }

    public function handle(StockOffAct $act, StockOffStatus $to, User $user, ?string $reason = null): StockOffAct
    {
        if ($to === StockOffStatus::Posted) {
            return $this->post->handle($act, $user);
        }

        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;

        return DB::transaction(function () use ($act, $to, $user, $reason): StockOffAct {
            $act = StockOffAct::query()->lockForUpdate()->findOrFail($act->id);
            $from = $act->status;

            if (self::ability($act, $to) === null) {
                throw new StockOffException('invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
            }

            if (! self::allows($user, $act, $to)) {
                throw new StockOffException(in_array($to, [StockOffStatus::Approved, StockOffStatus::Rejected], true) && PermissionMap::allows($user, (string) self::ability($act, $to)) ? 'own_act' : 'forbidden', ['to' => $to->label()]);
            }

            if ($reason === null && in_array($to, [StockOffStatus::Rejected, StockOffStatus::Cancelled], true)) {
                throw new StockOffException('reason_required');
            }

            $attributes = ['status' => $to];

            if ($to === StockOffStatus::Submitted) {
                $this->assertComplete($act);
                app(ManageStockOffAct::class)->revalue($act);
                $attributes += ['submitted_by' => $user->id, 'submitted_at' => now()];
            }

            if (in_array($to, [StockOffStatus::Approved, StockOffStatus::Rejected], true)) {
                $attributes += ['decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $reason];
            }

            if ($to === StockOffStatus::Draft) {
                $attributes += ['submitted_by' => null, 'submitted_at' => null];
            }

            $act->forceFill($attributes)->save();

            if ($to === StockOffStatus::Cancelled) {
                StockOffLineSerial::query()->whereIn('line_id', $act->lines()->select('id'))->update(['active_key' => null]);
            }

            self::record($act, $from, $to, $user, $reason);

            return $act;
        });
    }

    protected function assertComplete(StockOffAct $act): void
    {
        $lines = $act->lines()->withCount('serials')->get();

        if ($lines->isEmpty() || $lines->contains(fn ($line): bool => bccomp((string) $line->quantity, '0', Quantity::SCALE) <= 0)) {
            throw new StockOffException('lines_incomplete');
        }

        if ($act->reason->needsNote() && trim((string) $act->note) === '') {
            throw new StockOffException('note_required');
        }
    }

    public static function record(StockOffAct $act, ?StockOffStatus $from, StockOffStatus $to, ?User $user, ?string $reason = null): void
    {
        StatusTransition::query()->create([
            'subject_type' => $act->getMorphClass(),
            'subject_id' => $act->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $user?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
