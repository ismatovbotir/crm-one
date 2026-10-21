<?php

namespace App\Actions\Offers;

use App\Enums\ApprovalStatus;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;

/**
 * Guard at the start of every content change (Spec 05 6.2): drafts are editable; an approved
 * offer returns to draft and its approval is invalidated (AC-11, logged); everything else must
 * be revised instead.
 */
class BeginOfferEdit
{
    public function __construct(protected TransitionOffer $transition) {}

    public function handle(Offer $offer, ?User $user): void
    {
        if ($offer->status === OfferStatus::Draft) {
            return;
        }

        if ($offer->status !== OfferStatus::Approved) {
            throw new OfferActionException('not_editable', ['status' => $offer->status->label()]);
        }

        $offer->approvals()->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved])->update(['status' => ApprovalStatus::Invalidated]);
        $this->transition->handle($offer, OfferStatus::Draft, $user, 'approval_invalidated', ['approved_hash' => null, 'approved_at' => null, 'approved_by' => null]);
        $offer->logActivity('approval_invalidated');
    }
}
