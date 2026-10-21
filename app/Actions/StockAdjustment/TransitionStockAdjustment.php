<?php

namespace App\Actions\StockAdjustment;

use App\Authorization\PermissionMap;
use App\Enums\StockAdjustmentStatus;
use App\Models\StatusTransition;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\Stock\Exceptions\StockAdjustmentException;
use Illuminate\Support\Facades\DB;

/**
 * The only place a stock adjustment's status changes (Spec 06 section 21.4). Who moves what:
 * - submit, rework a rejected adjustment, cancel a draft / rejected one: stock staff (`stock_adjust.create`);
 * - approve (which posts it) / reject: the warehouse head or a manager (`stock.adjust_approve`),
 *   never the requester or the submitter (maker != checker);
 * - cancel a submitted one: an approver.
 * Reject and cancel need a reason. Every move is written to `status_transitions`.
 */
class TransitionStockAdjustment
{
    public function __construct(protected PostStockAdjustment $post) {}

    public static function ability(StockAdjustment $adjustment, StockAdjustmentStatus $to): ?string
    {
        $from = $adjustment->status;

        if (! $from->canTransitionTo($to)) {
            return null;
        }

        return match ($to) {
            StockAdjustmentStatus::PendingApproval, StockAdjustmentStatus::Draft => 'stock_adjust.create',
            StockAdjustmentStatus::Posted, StockAdjustmentStatus::Rejected => 'stock.adjust_approve',
            StockAdjustmentStatus::Cancelled => in_array($from, [StockAdjustmentStatus::Draft, StockAdjustmentStatus::Rejected], true) ? 'stock_adjust.create' : 'stock.adjust_approve',
        };
    }

    public static function allows(User $user, StockAdjustment $adjustment, StockAdjustmentStatus $to): bool
    {
        $ability = self::ability($adjustment, $to);

        if ($ability === null || ! PermissionMap::allows($user, $ability)) {
            return false;
        }

        return ! in_array($to, [StockAdjustmentStatus::Posted, StockAdjustmentStatus::Rejected], true)
            || ! in_array($user->id, array_filter([$adjustment->created_by, $adjustment->submitted_by]), true);
    }

    public function handle(StockAdjustment $adjustment, StockAdjustmentStatus $to, User $user, ?string $reason = null): StockAdjustment
    {
        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;

        return DB::transaction(function () use ($adjustment, $to, $user, $reason): StockAdjustment {
            $adjustment = StockAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            $from = $adjustment->status;

            if (self::ability($adjustment, $to) === null) {
                throw new StockAdjustmentException('invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
            }

            if (! self::allows($user, $adjustment, $to)) {
                $ownRecord = in_array($to, [StockAdjustmentStatus::Posted, StockAdjustmentStatus::Rejected], true) && PermissionMap::allows($user, (string) self::ability($adjustment, $to));

                throw new StockAdjustmentException($ownRecord ? 'own_adjustment' : 'forbidden', ['to' => $to->label()]);
            }

            if ($reason === null && in_array($to, [StockAdjustmentStatus::Rejected, StockAdjustmentStatus::Cancelled], true)) {
                throw new StockAdjustmentException('reason_required');
            }

            if ($to === StockAdjustmentStatus::PendingApproval && $adjustment->lines()->doesntExist()) {
                throw new StockAdjustmentException('lines_incomplete');
            }

            $attributes = ['status' => $to];

            if ($to === StockAdjustmentStatus::PendingApproval) {
                $attributes += ['submitted_by' => $user->id, 'submitted_at' => now()];
            }

            if ($to === StockAdjustmentStatus::Draft) {
                $attributes += ['submitted_by' => null, 'submitted_at' => null];
            }

            if (in_array($to, [StockAdjustmentStatus::Posted, StockAdjustmentStatus::Rejected], true)) {
                $attributes += ['decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $reason];
            }

            if ($to === StockAdjustmentStatus::Posted) {
                $this->post->handle($adjustment, $user);
                $attributes += ['posted_at' => now()];
            }

            $adjustment->forceFill($attributes)->save();
            self::record($adjustment, $from, $to, $user, $reason);

            if ($to === StockAdjustmentStatus::Posted) {
                $this->post->publish($adjustment);
            }

            return $adjustment;
        });
    }

    public static function record(StockAdjustment $adjustment, ?StockAdjustmentStatus $from, StockAdjustmentStatus $to, ?User $user, ?string $reason = null): void
    {
        StatusTransition::query()->create([
            'subject_type' => $adjustment->getMorphClass(),
            'subject_id' => $adjustment->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $user?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
