<?php

namespace App\Authorization;

use App\Enums\DataScope;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads config/permissions.php: which data scope a user has for an ability, combining the
 * global role with department memberships (widest grant wins, Spec 03 BR-O.2).
 * Policies combine the scope with record facts (owner); list queries use applyScope().
 */
final class PermissionMap
{
    /**
     * Widest scope granted for the ability, or null when denied.
     */
    public static function scopeFor(User $user, string $ability): ?DataScope
    {
        if (! $user->isInternal() || ! $user->is_active) {
            return null;
        }

        // Ability names contain dots, so they cannot be read with config() dot notation.
        $grants = config('permissions.abilities', [])[$ability] ?? null;

        if (! is_array($grants)) {
            return null;
        }

        $scope = null;

        foreach ($grants as $grant => $value) {
            if (self::matches($user, (string) $grant)) {
                $scope = DataScope::widest($scope, DataScope::from($value));
            }
        }

        return $scope;
    }

    public static function allows(User $user, string $ability): bool
    {
        return self::scopeFor($user, $ability) !== null;
    }

    /**
     * Whether the ability covers a record owned by $ownerId.
     */
    public static function allowsFor(User $user, string $ability, ?int $ownerId): bool
    {
        return match (self::scopeFor($user, $ability)) {
            DataScope::All => true,
            DataScope::Department => $ownerId !== null && ($ownerId === $user->id || in_array($ownerId, self::colleagueIds($user), true)),
            DataScope::Own, DataScope::Linked => $ownerId !== null && $ownerId === $user->id,
            null => false,
        };
    }

    /**
     * Restrict a query to the records the user may access for the ability.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyScope(Builder $query, User $user, string $ability, string $ownerColumn = 'owner_id'): Builder
    {
        $column = $query->qualifyColumn($ownerColumn);

        return match (self::scopeFor($user, $ability)) {
            DataScope::All => $query,
            DataScope::Department => $query->whereIn($column, [$user->id, ...self::colleagueIds($user)]),
            DataScope::Own, DataScope::Linked => $query->where($column, $user->id),
            null => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Ids of users sharing at least one active department with the user (excluding the user).
     *
     * @return list<int>
     */
    public static function colleagueIds(User $user): array
    {
        $departmentIds = $user->activeDepartments()->pluck('id')->all();

        if ($departmentIds === []) {
            return [];
        }

        return DB::table('department_user')
            ->whereIn('department_id', $departmentIds)
            ->where('user_id', '!=', $user->id)
            ->distinct()
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Whether a grant key (role:x or dept:code:role) applies to the user. Used by other
     * grant-keyed config such as offer discount limits.
     */
    public static function matchesGrant(User $user, string $grant): bool
    {
        return $user->isInternal() && $user->is_active && self::matches($user, $grant);
    }

    private static function matches(User $user, string $grant): bool
    {
        $parts = explode(':', $grant);

        if ($parts[0] === 'role') {
            return $user->role !== null && $user->role->value === ($parts[1] ?? null);
        }

        if ($parts[0] !== 'dept' || count($parts) !== 3) {
            return false;
        }

        [, $code, $role] = $parts;

        return $user->activeDepartments()->contains(
            fn (Department $department): bool => ($code === '*' || $department->code === $code)
                && ($role === '*' || $department->membership->role->value === $role),
        );
    }
}
