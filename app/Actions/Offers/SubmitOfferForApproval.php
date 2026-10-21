<?php

namespace App\Actions\Offers;

use App\Enums\ApprovalStatus;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\OfferApproval;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Services\Offers\DiscountRules;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\Offers\OfferValidator;
use Illuminate\Support\Facades\DB;

/**
 * draft -> pending_approval (Spec 05 6.1) when validation passes and the worst-case discount
 * exceeds the owner's limit (DEC-6). The approval request is bound to the content hash.
 */
class SubmitOfferForApproval
{
    public function __construct(
        protected TransitionOffer $transition,
        protected RecalculateOffer $recalculate,
        protected OfferValidator $validator,
        protected DiscountRules $rules,
    ) {}

    public function handle(Offer $offer, User $user): Offer
    {
        if ($offer->status !== OfferStatus::Draft) {
            throw new OfferActionException('invalid_transition', ['from' => $offer->status->label(), 'to' => OfferStatus::PendingApproval->label()]);
        }

        $this->recalculate->handle($offer);

        if (OfferValidator::hasBlockers($this->validator->validate($offer))) {
            throw new OfferActionException('validation_blockers');
        }

        if (! $this->rules->requiresApproval($offer)) {
            throw new OfferActionException('approval_not_required');
        }

        return DB::transaction(function () use ($offer, $user): Offer {
            OfferApproval::query()->create([
                'offer_id' => $offer->id,
                'required_bp' => $offer->max_discount_bp,
                'owner_limit_bp' => $this->rules->limitFor($offer->owner),
                'reasons' => $this->rules->reasons($offer),
                'status' => ApprovalStatus::Pending,
                'requested_by' => $user->id,
                'requested_at' => now(),
                'content_hash' => $offer->content_hash,
            ]);

            $offer = $this->transition->handle($offer, OfferStatus::PendingApproval, $user, null, ['submitted_at' => now(), 'submitted_by' => $user->id]);
            DB::afterCommit(fn () => app(Notifier::class)->offerApproval($offer, $user));

            return $offer;
        });
    }
}
