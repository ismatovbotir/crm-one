<?php

namespace App\Actions\Offers;

use App\Enums\ApprovalStatus;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\OfferApproval;
use App\Models\User;
use App\Services\Offers\DiscountRules;
use App\Services\Offers\Exceptions\OfferActionException;
use Illuminate\Support\Facades\DB;

/**
 * Approver decision on a pending offer (Spec 05 6.1, AC-12): approve (-> approved, hash
 * recorded) or return with a mandatory comment (-> draft). Never the owner, never without the
 * required limit, never on content changed since submission.
 */
class DecideOfferApproval
{
    public function __construct(protected TransitionOffer $transition, protected DiscountRules $rules) {}

    public function approve(Offer $offer, User $user, ?string $comment = null): Offer
    {
        $approval = $this->pendingApproval($offer, $user);

        return DB::transaction(function () use ($offer, $user, $comment, $approval): Offer {
            $approval->forceFill(['status' => ApprovalStatus::Approved, 'decided_by' => $user->id, 'decided_at' => now(), 'comment' => $comment])->save();

            return $this->transition->handle($offer, OfferStatus::Approved, $user, $comment, [
                'approved_hash' => $offer->content_hash,
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);
        });
    }

    public function returnToDraft(Offer $offer, User $user, string $comment): Offer
    {
        if (trim($comment) === '') {
            throw new OfferActionException('comment_required');
        }

        $approval = $this->pendingApproval($offer, $user);

        return DB::transaction(function () use ($offer, $user, $comment, $approval): Offer {
            $approval->forceFill(['status' => ApprovalStatus::Returned, 'decided_by' => $user->id, 'decided_at' => now(), 'comment' => $comment])->save();

            return $this->transition->handle($offer, OfferStatus::Draft, $user, $comment);
        });
    }

    protected function pendingApproval(Offer $offer, User $user): OfferApproval
    {
        if ($offer->status !== OfferStatus::PendingApproval) {
            throw new OfferActionException('not_pending_approval');
        }

        if (! $this->rules->canApprove($user, $offer)) {
            throw new OfferActionException('cannot_approve');
        }

        $approval = $offer->approvals()->where('status', ApprovalStatus::Pending)->first();

        if ($approval === null || $approval->content_hash !== $offer->content_hash) {
            throw new OfferActionException('content_changed');
        }

        return $approval;
    }
}
