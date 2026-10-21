<?php

namespace App\Actions\Offers;

use App\Actions\Requisitions\ManageRequisition;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use Illuminate\Support\Facades\DB;

/**
 * The only place an offer's status changes (Spec 05 6.1): checks the allowed-transition map and
 * writes `status_transitions` (who/from/to/when/why) plus the activity log (`status_changed`).
 */
class TransitionOffer
{
    /**
     * @param  array<string, mixed>  $attributes  columns written together with the status
     */
    public function handle(Offer $offer, OfferStatus $to, ?User $user, ?string $reason = null, array $attributes = []): Offer
    {
        $from = $offer->status;

        if (! $from->canTransitionTo($to)) {
            throw new OfferActionException('invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
        }

        return DB::transaction(function () use ($offer, $from, $to, $user, $reason, $attributes): Offer {
            $offer->forceFill([...$attributes, 'status' => $to])->save();

            StatusTransition::query()->create([
                'subject_type' => $offer->getMorphClass(),
                'subject_id' => $offer->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'user_id' => $user?->id,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            // Spec 10: an offer answering a customer request moves the request (offered / ordered / quoting).
            if ($offer->requisition_id !== null) {
                app(ManageRequisition::class)->offerChanged($offer, $to->value, $user);
            }

            return $offer;
        });
    }

    /**
     * First history row of a new offer (no previous status).
     */
    public static function recordCreation(Offer $offer, ?User $user, ?string $reason = null): void
    {
        StatusTransition::query()->create([
            'subject_type' => $offer->getMorphClass(),
            'subject_id' => $offer->id,
            'from_status' => null,
            'to_status' => $offer->status->value,
            'user_id' => $user?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
