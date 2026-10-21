<?php

namespace App\Actions\Departments;

use Illuminate\Support\Facades\DB;

/**
 * Non-breaking backfill (Spec 03 section 4.9): gives existing internal users a primary
 * department from their global role. Uses the query builder only, so it is safe to run
 * from a migration. Users that already have a membership are skipped.
 *
 * sales → sales/specialist, finance → finance/specialist,
 * manager → management/head (first manager) or specialist, admin → it/head (first admin) or specialist.
 */
class BackfillDepartmentMemberships
{
    /** Seeded department codes, in sidebar order. */
    public const STANDARD_DEPARTMENTS = ['sales', 'purchase', 'logistics', 'finance', 'management', 'it', 'support', 'warehouse', 'service'];

    /** @var array<string, array{0: string, 1: string}> global role => [department code, department role] */
    private const MAPPING = [
        'sales' => ['sales', 'specialist'],
        // Spec 06 DEC-2: finance = controller (chief accountant = head), accountant = operator (specialist).
        'finance' => ['finance', 'head'],
        'accountant' => ['finance', 'specialist'],
        'stock_manager' => ['warehouse', 'specialist'],
        'manager' => ['management', 'head'],
        'admin' => ['it', 'head'],
        // Spec 09: service engineers belong to the service department.
        'service_engineer' => ['service', 'specialist'],
    ];

    /**
     * @return int number of memberships created
     */
    public function handle(): int
    {
        $departments = DB::table('departments')->pluck('id', 'code');
        $created = 0;

        $users = DB::table('users')
            ->where('type', 'internal')
            ->whereIn('role', array_keys(self::MAPPING))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('department_user')->whereColumn('department_user.user_id', 'users.id'))
            ->orderBy('id')
            ->get(['id', 'role']);

        foreach ($users as $user) {
            [$code, $role] = self::MAPPING[$user->role];
            $departmentId = $departments[$code] ?? null;

            if ($departmentId === null) {
                continue;
            }

            if ($role === 'head' && DB::table('department_user')->where('department_id', $departmentId)->where('role', 'head')->exists()) {
                $role = 'specialist';
            }

            DB::table('department_user')->insert([
                'department_id' => $departmentId,
                'user_id' => $user->id,
                'role' => $role,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created++;
        }

        return $created;
    }
}
