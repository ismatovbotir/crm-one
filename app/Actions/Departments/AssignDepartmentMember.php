<?php

namespace App\Actions\Departments;

use App\Enums\DepartmentRole;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a user to a department or changes their membership (Spec 03 BR-O.1):
 * internal users only, exactly one head per department, one primary department per user.
 * Every change is written to the department's change log.
 */
class AssignDepartmentMember
{
    public function handle(Department $department, User $user, DepartmentRole $role, bool $primary = false, string $errorKey = 'member'): DepartmentMember
    {
        if (! $user->isInternal()) {
            throw ValidationException::withMessages([$errorKey => __('departments.errors.portal_user')]);
        }

        return DB::transaction(function () use ($department, $user, $role, $primary, $errorKey): DepartmentMember {
            if ($role === DepartmentRole::Head) {
                $otherHead = DepartmentMember::query()
                    ->where('department_id', $department->id)
                    ->where('role', DepartmentRole::Head)
                    ->where('user_id', '!=', $user->id)
                    ->lockForUpdate()
                    ->with('user')
                    ->first();

                if ($otherHead !== null) {
                    throw ValidationException::withMessages([
                        $errorKey => __('departments.errors.head_taken', ['name' => $otherHead->user->name]),
                    ]);
                }
            }

            $membership = DepartmentMember::query()
                ->where('department_id', $department->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            $isFirstMembership = $membership === null
                && ! DepartmentMember::query()->where('user_id', $user->id)->exists();

            // A user always keeps one primary department: it moves only when another one is made primary.
            $primary = $primary || $isFirstMembership || (bool) $membership?->is_primary;

            if ($primary) {
                DepartmentMember::query()
                    ->where('user_id', $user->id)
                    ->where('department_id', '!=', $department->id)
                    ->update(['is_primary' => false]);
            }

            $old = $membership === null ? null : ['role' => $membership->role->value, 'is_primary' => $membership->is_primary];

            $membership ??= new DepartmentMember(['department_id' => $department->id, 'user_id' => $user->id]);
            $membership->role = $role;
            $membership->is_primary = $primary;
            $membership->save();

            $new = ['user' => $user->name, 'role' => $role->value, 'is_primary' => $primary];

            if ($old === null) {
                $department->logActivity('member_added', ['new' => $new]);
            } elseif ($old['role'] !== $role->value || $old['is_primary'] !== $primary) {
                $department->logActivity('member_changed', ['old' => ['user' => $user->name, ...$old], 'new' => $new]);
            }

            $user->unsetRelation('departments');

            return $membership;
        });
    }

    public function remove(Department $department, User $user): void
    {
        DB::transaction(function () use ($department, $user): void {
            $membership = DepartmentMember::query()
                ->where('department_id', $department->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                return;
            }

            $membership->delete();

            if ($membership->is_primary) {
                DepartmentMember::query()->where('user_id', $user->id)->orderBy('id')->limit(1)->update(['is_primary' => true]);
            }

            $department->logActivity('member_removed', ['old' => ['user' => $user->name, 'role' => $membership->role->value]]);
            $user->unsetRelation('departments');
        });
    }
}
