<?php

namespace Tests\Feature\Organization;

use App\Actions\Departments\BackfillDepartmentMemberships;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec 03 section 4.9 backfill: existing global roles map to department memberships.
 */
class DepartmentBackfillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{department: string, role: string, is_primary: int|bool}>
     */
    private function membershipOf(User $user): array
    {
        $row = DB::table('department_user')
            ->join('departments', 'departments.id', '=', 'department_user.department_id')
            ->where('user_id', $user->id)
            ->first(['departments.code', 'department_user.role', 'department_user.is_primary']);

        return ['department' => $row->code, 'role' => $row->role, 'is_primary' => (bool) $row->is_primary];
    }

    public function test_roles_are_mapped_to_departments(): void
    {
        $sales = User::factory()->sales()->create();
        $finance = User::factory()->finance()->create();
        $accountant = User::factory()->accountant()->create();
        $firstManager = User::factory()->manager()->create();
        $secondManager = User::factory()->manager()->create();
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $portal = User::factory()->portal()->create();

        $created = (new BackfillDepartmentMemberships)->handle();

        $this->assertSame(6, $created);
        $this->assertSame(['department' => 'sales', 'role' => 'specialist', 'is_primary' => true], $this->membershipOf($sales));
        // Spec 06 DEC-2: finance = controller (head), accountant = operator (specialist).
        $this->assertSame(['department' => 'finance', 'role' => 'head', 'is_primary' => true], $this->membershipOf($finance));
        $this->assertSame(['department' => 'finance', 'role' => 'specialist', 'is_primary' => true], $this->membershipOf($accountant));
        $this->assertSame(['department' => 'management', 'role' => 'head', 'is_primary' => true], $this->membershipOf($firstManager));
        $this->assertSame(['department' => 'management', 'role' => 'specialist', 'is_primary' => true], $this->membershipOf($secondManager));
        $this->assertSame(['department' => 'it', 'role' => 'head', 'is_primary' => true], $this->membershipOf($admin));
        $this->assertFalse(DB::table('department_user')->whereIn('user_id', [$staff->id, $portal->id])->exists());
    }

    public function test_backfill_is_idempotent_and_keeps_existing_memberships(): void
    {
        $rep = User::factory()->sales()->inDepartment('support')->create();

        $this->assertSame(0, (new BackfillDepartmentMemberships)->handle());
        $this->assertSame(1, DB::table('department_user')->where('user_id', $rep->id)->count());
    }
}
