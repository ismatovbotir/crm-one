<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\OrderAmendment;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Agreements\Exceptions\AgreementActionException;
use Illuminate\Support\Facades\DB;

/**
 * The only place an agreement's status changes (Spec 06 6.5): checks the allowed-transition map
 * and writes `status_transitions` plus the activity log (`status_changed`).
 */
class TransitionAgreement
{
    /**
     * @param  array<string, mixed>  $attributes  columns written together with the status
     */
    public function handle(Agreement $agreement, AgreementStatus $to, ?User $user, ?string $reason = null, array $attributes = []): Agreement
    {
        $from = $agreement->status;

        if (! $from->canTransitionTo($to)) {
            throw new AgreementActionException('invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
        }

        return DB::transaction(function () use ($agreement, $from, $to, $user, $reason, $attributes): Agreement {
            $agreement->forceFill([...$attributes, 'status' => $to])->save();
            self::record($agreement, $from, $to, $user, $reason);

            // An addendum from an order amendment settles the order's "addendum pending" flag (Spec 06 6.4).
            if ($agreement->kind === AgreementKind::Addendum) {
                OrderAmendment::query()->where('addendum_agreement_id', $agreement->id)->with('order')->get()->each(fn (OrderAmendment $amendment) => $amendment->order->syncAddendumPending());
            }

            return $agreement;
        });
    }

    public static function record(Agreement $agreement, ?AgreementStatus $from, AgreementStatus $to, ?User $user, ?string $reason = null): void
    {
        StatusTransition::query()->create([
            'subject_type' => $agreement->getMorphClass(),
            'subject_id' => $agreement->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $user?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
