<?php

namespace App\Services\Leads;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Users a lead may be assigned to by the given user (Spec 04 section 3): active internal users who
 * may create leads, within the assigner's `leads.assign` scope (department colleagues or all).
 */
class LeadOwners
{
    /**
     * @return Collection<int, User>
     */
    public function assignableBy(User $user): Collection
    {
        $scope = PermissionMap::scopeFor($user, 'leads.assign');

        if ($scope === null) {
            return collect([$user]);
        }

        $ids = $scope === DataScope::All ? null : [$user->id, ...PermissionMap::colleagueIds($user)];

        return User::query()->where('is_active', true)
            ->when($ids !== null, fn ($query) => $query->whereKey($ids))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $candidate): bool => $candidate->isInternal() && $candidate->can('create', Lead::class))
            ->values();
    }
}
