<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\LeadStatus;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;

/**
 * Spec 04 section 3 via config/permissions.php (`leads.*`): sales see and work their own leads,
 * sales heads their department, managers all; admins read only. Converted, disqualified and lost
 * leads are read only except notes and reopen (BR-9).
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'leads.view');
    }

    public function view(User $user, Lead $lead): bool
    {
        if ($lead->owner_id === null) {
            return in_array(PermissionMap::scopeFor($user, 'leads.view'), [DataScope::All, DataScope::Department], true);
        }

        return PermissionMap::allowsFor($user, 'leads.view', $lead->owner_id);
    }

    public function create(User $user): bool
    {
        return PermissionMap::allows($user, 'leads.create');
    }

    /** Edit data and interests (open leads only). */
    public function update(User $user, Lead $lead): bool
    {
        return $lead->status->isOpen() && $this->manages($user, $lead);
    }

    /** Log calls, e-mails, meetings and notes (any status). */
    public function logActivity(User $user, Lead $lead): bool
    {
        return $this->manages($user, $lead);
    }

    public function markContacted(User $user, Lead $lead): bool
    {
        return $lead->status === LeadStatus::New && $this->manages($user, $lead);
    }

    public function qualify(User $user, Lead $lead): bool
    {
        return in_array($lead->status, [LeadStatus::New, LeadStatus::Contacted], true) && $this->manages($user, $lead);
    }

    public function close(User $user, Lead $lead): bool
    {
        return $lead->status->isOpen() && $this->manages($user, $lead);
    }

    public function reopen(User $user, Lead $lead): bool
    {
        return $lead->status->isClosed() && $lead->anonymised_at === null && $lead->merged_into_id === null && $this->manages($user, $lead);
    }

    /**
     * "Convert to customer" (Spec 04 section 18): open lead the user manages (leads.update) and
     * the right to create customers (customers.create). Admins stay read only.
     */
    public function convertToCustomer(User $user, Lead $lead): bool
    {
        return $lead->status->isOpen() && $this->manages($user, $lead) && $user->can('create', Customer::class);
    }

    /** Merge a duplicate into another lead (Spec 04 section 20): open, managed by the user. */
    public function merge(User $user, Lead $lead): bool
    {
        return $lead->status->isOpen() && $this->manages($user, $lead);
    }

    public function assign(User $user, Lead $lead): bool
    {
        $allowed = $lead->owner_id === null
            ? PermissionMap::allows($user, 'leads.assign')
            : PermissionMap::allowsFor($user, 'leads.assign', $lead->owner_id);

        return $lead->status->isOpen() && $this->view($user, $lead) && $allowed;
    }

    public function viewActivity(User $user, Lead $lead): bool
    {
        return $this->view($user, $lead);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return false;
    }

    public function forceDelete(User $user, Lead $lead): bool
    {
        return false;
    }

    private function manages(User $user, Lead $lead): bool
    {
        if ($lead->owner_id === null) {
            return $this->view($user, $lead) && in_array(PermissionMap::scopeFor($user, 'leads.update'), [DataScope::All, DataScope::Department], true);
        }

        return $this->view($user, $lead) && PermissionMap::allowsFor($user, 'leads.update', $lead->owner_id);
    }
}
