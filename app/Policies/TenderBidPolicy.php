<?php

namespace App\Policies;

use App\Enums\BidStatus;
use App\Enums\TenderStatus;
use App\Models\TenderBid;
use App\Models\User;
use App\Services\Tenders\TenderAccess;

/**
 * Sealed bids (BR-T.5, AC-21; Spec 08 BR-B.4): content only through viewContent — before opening
 * only the employee who entered that version, after opening the tender department, management,
 * finance and committee members with a conflict-of-interest declaration; never an admin.
 */
class TenderBidPolicy
{
    public function viewContent(User $user, TenderBid $bid): bool
    {
        return TenderAccess::canViewBidContent($user, $bid);
    }

    public function withdraw(User $user, TenderBid $bid): bool
    {
        $tender = $bid->tender;

        return $bid->status === BidStatus::Submitted
            && $tender->status === TenderStatus::Published
            && $tender->bids_due_at?->isFuture()
            && ($bid->entered_by === $user->id || TenderAccess::canManage($user, $tender));
    }

    public function delete(User $user, TenderBid $bid): bool
    {
        return false;
    }
}
