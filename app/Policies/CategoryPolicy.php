<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\Category;
use App\Models\User;

/**
 * Categories are 1C item groups: visible to every catalog user, never edited in the CRM.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'catalog.view');
    }

    public function view(User $user, Category $category): bool
    {
        return PermissionMap::allows($user, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Category $category): bool
    {
        return false;
    }

    public function delete(User $user, Category $category): bool
    {
        return false;
    }
}
