<?php

namespace App\Support;

use App\Enums\CustomerPortalRole;
use App\Models\Customer;
use App\Models\PortalMembership;
use App\Models\User;
use LogicException;

/**
 * The signed-in portal user's company for this request (Spec 03 4.1): set by EnsurePortalUser from
 * the active membership. Every portal query is scoped through customerId(); records of another
 * company answer 404 (DEC-13).
 */
final class PortalContext
{
    private ?User $user = null;

    private ?PortalMembership $membership = null;

    public function set(User $user, PortalMembership $membership): void
    {
        $this->user = $user;
        $this->membership = $membership;
    }

    public function active(): bool
    {
        return $this->membership !== null;
    }

    public function user(): User
    {
        return $this->user ?? throw new LogicException('No portal context.');
    }

    public function membership(): PortalMembership
    {
        return $this->membership ?? throw new LogicException('No portal context.');
    }

    public function customer(): Customer
    {
        return $this->membership()->customer;
    }

    public function customerId(): int
    {
        return (int) $this->membership()->customer_id;
    }

    public function hasRole(CustomerPortalRole ...$roles): bool
    {
        return $this->membership !== null && $this->membership->hasRole(...$roles);
    }

    /** Invoices, agreements and payment status (Spec 03 4.8). */
    public function canSeeFinance(): bool
    {
        return $this->hasRole(CustomerPortalRole::PortalAdmin, CustomerPortalRole::Approver, CustomerPortalRole::Finance);
    }

    /**
     * May accept an offer of this amount (company currency minor units): buyers up to the company
     * threshold, approvers up to their own limit (none = unlimited), portal admins always.
     */
    public function canAccept(int $amountMinor): bool
    {
        if ($this->hasRole(CustomerPortalRole::PortalAdmin)) {
            return true;
        }

        $threshold = $this->customer()->portal_approval_threshold_minor;

        if ($this->hasRole(CustomerPortalRole::Approver)) {
            $limit = $this->membership()->approval_limit_minor;

            return $limit === null || $amountMinor <= $limit;
        }

        return $this->hasRole(CustomerPortalRole::Buyer) && ($threshold === null || $amountMinor <= $threshold);
    }

    /** Register equipment, report problems, mark units stored (Spec 09 §7.2); viewer and finance read only. */
    public function canManageEquipment(): bool
    {
        return $this->hasRole(CustomerPortalRole::PortalAdmin, CustomerPortalRole::Buyer, CustomerPortalRole::Approver);
    }

    /** Spec 10: may create requests (requisitions). Viewer and finance only read. */
    public function canRequest(): bool
    {
        return $this->hasRole(CustomerPortalRole::Requester, CustomerPortalRole::PurchaseManager, CustomerPortalRole::PortalAdmin, CustomerPortalRole::Buyer, CustomerPortalRole::Approver);
    }

    /** Spec 10: reviews, consolidates and sends requests to the supplier (us). */
    public function canPurchase(): bool
    {
        return $this->hasRole(CustomerPortalRole::PurchaseManager, CustomerPortalRole::PortalAdmin);
    }

    public function canManageUsers(): bool
    {
        return $this->hasRole(CustomerPortalRole::PortalAdmin);
    }
}
