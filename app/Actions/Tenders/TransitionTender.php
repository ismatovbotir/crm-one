<?php

namespace App\Actions\Tenders;

use App\Enums\TenderStatus;
use App\Models\StatusTransition;
use App\Models\Tender;
use App\Models\User;
use App\Services\Tenders\Exceptions\TenderActionException;
use Illuminate\Support\Facades\DB;

/**
 * The only place a tender's status changes (Spec 03 6.1): checks the transition map and writes
 * `status_transitions` (who/from/to/when/why) plus the activity log (`status_changed`).
 */
class TransitionTender
{
    /**
     * @param  array<string, mixed>  $attributes  columns written together with the status
     */
    public function handle(Tender $tender, TenderStatus $to, ?User $user, ?string $reason = null, array $attributes = []): Tender
    {
        $from = $tender->status;

        if (! $from->canTransitionTo($to)) {
            throw new TenderActionException('invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
        }

        return DB::transaction(function () use ($tender, $from, $to, $user, $reason, $attributes): Tender {
            $tender->forceFill([...$attributes, 'status' => $to])->save();
            self::record($tender, $from, $to, $user, $reason);

            return $tender;
        });
    }

    public static function record(Tender $tender, ?TenderStatus $from, TenderStatus $to, ?User $user, ?string $reason = null): void
    {
        StatusTransition::query()->create([
            'subject_type' => $tender->getMorphClass(),
            'subject_id' => $tender->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $user?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
