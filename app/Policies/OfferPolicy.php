<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\User;
use App\Services\Offers\DiscountRules;

/**
 * Spec 05 section 4.1 via config/permissions.php (`offers.*`). Visibility: sales see their own
 * offers and offers of customers they own (linked), department heads their department, managers,
 * finance and admin everything. Finance and admin are read-only.
 */
class OfferPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'offers.view');
    }

    public function view(User $user, Offer $offer): bool
    {
        return match (PermissionMap::scopeFor($user, 'offers.view')) {
            DataScope::All => true,
            DataScope::Department => $offer->owner_id === $user->id || in_array($offer->owner_id, PermissionMap::colleagueIds($user), true),
            DataScope::Linked => $offer->owner_id === $user->id || $offer->customer?->owner_id === $user->id,
            DataScope::Own => $offer->owner_id === $user->id,
            null => false,
        };
    }

    public function create(User $user): bool
    {
        return PermissionMap::allows($user, 'offers.create');
    }

    /**
     * Change content (draft, or approved which returns to draft).
     */
    public function update(User $user, Offer $offer): bool
    {
        return $offer->status->isEditable() && $this->manages($user, $offer);
    }

    public function submit(User $user, Offer $offer): bool
    {
        return $offer->status === OfferStatus::Draft && $this->manages($user, $offer);
    }

    public function approve(User $user, Offer $offer): bool
    {
        return $offer->status === OfferStatus::PendingApproval && app(DiscountRules::class)->canApprove($user, $offer);
    }

    public function send(User $user, Offer $offer): bool
    {
        return in_array($offer->status, [OfferStatus::Draft, OfferStatus::Approved], true) && $this->manages($user, $offer);
    }

    /**
     * Record the customer's choice / acceptance / rejection on their behalf.
     */
    public function recordDecision(User $user, Offer $offer): bool
    {
        return $offer->status->isOpen() && $this->manages($user, $offer);
    }

    public function revise(User $user, Offer $offer): bool
    {
        return $offer->status->isRevisable() && $this->manages($user, $offer);
    }

    /**
     * Cancel before sending (owner side) or withdraw an open offer (manager/department head).
     */
    public function cancel(User $user, Offer $offer): bool
    {
        if (in_array($offer->status, [OfferStatus::Draft, OfferStatus::PendingApproval, OfferStatus::Approved], true)) {
            return $this->manages($user, $offer);
        }

        return $offer->status->isOpen() && PermissionMap::allowsFor($user, 'offers.withdraw', $offer->owner_id);
    }

    /**
     * Fallback when the automatic order creation after acceptance did not run (Spec 06 DEC-1).
     */
    public function createOrder(User $user, Offer $offer): bool
    {
        return $offer->status === OfferStatus::Accepted && $offer->order === null && $this->manages($user, $offer);
    }

    public function print(User $user, Offer $offer): bool
    {
        return $this->view($user, $offer);
    }

    public function viewActivity(User $user, Offer $offer): bool
    {
        return $this->view($user, $offer) && PermissionMap::allows($user, 'activity.view');
    }

    public function delete(User $user, Offer $offer): bool
    {
        return false;
    }

    public function forceDelete(User $user, Offer $offer): bool
    {
        return false;
    }

    private function manages(User $user, Offer $offer): bool
    {
        return $this->view($user, $offer) && PermissionMap::allowsFor($user, 'offers.update', $offer->owner_id);
    }
}
