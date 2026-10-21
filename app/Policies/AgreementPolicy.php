<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\Order;
use App\Models\User;

/**
 * Spec 06 4.2/4.3 via config/permissions.php (`agreements.*`): accountants prepare, send and
 * confirm signatures; managers and finance approve non-standard terms and terminate; sales see the
 * agreements of customers they own and may upload a signed scan, but never confirm it (SoD-6).
 */
class AgreementPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'agreements.view');
    }

    public function view(User $user, Agreement $agreement): bool
    {
        return PermissionMap::allowsFor($user, 'agreements.view', $this->ownerId($agreement));
    }

    /** Generate a draft for an order (or a framework agreement for a customer). */
    public function create(User $user, ?Order $order = null): bool
    {
        return PermissionMap::allows($user, 'agreements.prepare') && ($order === null || ! $order->status->isFinal());
    }

    public function update(User $user, Agreement $agreement): bool
    {
        return $agreement->status->isEditable() && $this->prepares($user, $agreement);
    }

    public function submit(User $user, Agreement $agreement): bool
    {
        return $agreement->status === AgreementStatus::Draft && $this->prepares($user, $agreement);
    }

    /** Approve or return a reviewed agreement; never the preparer (checked again in the Action). */
    public function approve(User $user, Agreement $agreement): bool
    {
        return $agreement->status === AgreementStatus::InReview && $agreement->approved_at === null
            && $agreement->prepared_by !== $user->id && PermissionMap::allows($user, 'agreements.approve');
    }

    public function send(User $user, Agreement $agreement): bool
    {
        $ready = $agreement->status === AgreementStatus::Draft
            || ($agreement->status === AgreementStatus::InReview && $agreement->approved_at !== null)
            || $agreement->status === AgreementStatus::SentForSignature;

        return $ready && $this->view($user, $agreement) && PermissionMap::allows($user, 'agreements.send');
    }

    public function uploadScan(User $user, Agreement $agreement): bool
    {
        return $agreement->status === AgreementStatus::SentForSignature && PermissionMap::allowsFor($user, 'agreements.upload_scan', $this->ownerId($agreement));
    }

    public function confirmSigned(User $user, Agreement $agreement): bool
    {
        return $agreement->status === AgreementStatus::SentForSignature && PermissionMap::allows($user, 'agreements.confirm_signed');
    }

    public function reissue(User $user, Agreement $agreement): bool
    {
        return $agreement->status === AgreementStatus::SentForSignature && $this->prepares($user, $agreement);
    }

    public function cancel(User $user, Agreement $agreement): bool
    {
        return $agreement->status->canTransitionTo(AgreementStatus::Cancelled) && $this->prepares($user, $agreement);
    }

    public function terminate(User $user, Agreement $agreement): bool
    {
        return in_array($agreement->status, [AgreementStatus::Signed, AgreementStatus::Active], true) && PermissionMap::allows($user, 'agreements.terminate');
    }

    public function renew(User $user, Agreement $agreement): bool
    {
        return $agreement->status->isBinding() && $agreement->kind->value === 'framework' && $this->prepares($user, $agreement);
    }

    public function addAddendum(User $user, Agreement $agreement): bool
    {
        return $agreement->status->isBinding() && $this->prepares($user, $agreement);
    }

    public function print(User $user, Agreement $agreement): bool
    {
        return $this->view($user, $agreement);
    }

    public function downloadScan(User $user, Agreement $agreement): bool
    {
        return $this->view($user, $agreement);
    }

    public function viewActivity(User $user, Agreement $agreement): bool
    {
        return $this->view($user, $agreement) && PermissionMap::allows($user, 'activity.view');
    }

    public function delete(User $user, Agreement $agreement): bool
    {
        return false;
    }

    public function forceDelete(User $user, Agreement $agreement): bool
    {
        return false;
    }

    private function prepares(User $user, Agreement $agreement): bool
    {
        return $this->view($user, $agreement) && PermissionMap::allows($user, 'agreements.prepare');
    }

    /** Sales scope follows the customer owner (an order agreement: also the order owner). */
    private function ownerId(Agreement $agreement): ?int
    {
        return $agreement->order?->owner_id ?? $agreement->customer?->owner_id;
    }
}
