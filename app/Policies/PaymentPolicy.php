<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\PaymentKind;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;

/**
 * Spec 06 4.2/4.3/4.4 (`payments.*`): accountants record and allocate, finance approves (never
 * its own records — re-checked by user id in the Actions), sales see payments of their orders
 * without bank details. Payments are never updated or deleted (DEC-3).
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        if (PermissionMap::scopeFor($user, 'payments.view') === DataScope::All) {
            return true;
        }

        return PermissionMap::allows($user, 'payments.view') && Payment::query()->visibleTo($user)->whereKey($payment->id)->exists();
    }

    public function viewSensitive(User $user, Payment $payment): bool
    {
        return PermissionMap::allows($user, 'payments.view_sensitive');
    }

    public function create(User $user): bool
    {
        return PermissionMap::allows($user, 'payments.record');
    }

    public function allocate(User $user, Payment $payment): bool
    {
        return $payment->kind === PaymentKind::Receipt && $payment->status->isAllocatable() && PermissionMap::allows($user, 'payments.allocate');
    }

    public function approve(User $user, Payment $payment): bool
    {
        return $payment->status === PaymentStatus::PendingApproval && $payment->recorded_by !== $user->id && PermissionMap::allows($user, 'payments.approve');
    }

    public function requestVoid(User $user, Payment $payment): bool
    {
        return $payment->source === PaymentSource::Crm && $payment->void_requested_at === null
            && $payment->status->canTransitionTo(PaymentStatus::Voided) && PermissionMap::allows($user, 'payments.record');
    }

    public function approveVoid(User $user, Payment $payment): bool
    {
        return $payment->void_requested_at !== null && $payment->status->canTransitionTo(PaymentStatus::Voided)
            && ! in_array($user->id, [$payment->void_requested_by, $payment->recorded_by], true) && PermissionMap::allows($user, 'payments.approve');
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $payment->kind === PaymentKind::Receipt && $payment->status->isAllocatable() && $payment->unallocatedMinor() > 0 && PermissionMap::allows($user, 'payments.record');
    }

    /** Link an unmatched 1C payment to a customer, resolve a possible duplicate. */
    public function resolve(User $user, Payment $payment): bool
    {
        return PermissionMap::allows($user, 'payments.allocate');
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
