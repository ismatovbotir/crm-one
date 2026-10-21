<?php

namespace Tests\Feature\Organization;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\DepartmentRole;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermissionMapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{UserRole, string, DataScope|null}>
     */
    public static function roleGrants(): array
    {
        return [
            'sales updates own customers' => [UserRole::Sales, 'customers.update', DataScope::Own],
            'manager updates all customers' => [UserRole::Manager, 'customers.update', DataScope::All],
            'finance cannot update customers' => [UserRole::Finance, 'customers.update', null],
            'finance sets commercial terms' => [UserRole::Finance, 'customers.commercial_terms', DataScope::All],
            'sales sees all customers (D-7)' => [UserRole::Sales, 'customers.view', DataScope::All],
            'staff without department has nothing' => [UserRole::Staff, 'customers.view', null],
            'staff without department cannot see the catalog' => [UserRole::Staff, 'catalog.view', null],
            'admin manages departments' => [UserRole::Admin, 'departments.manage', DataScope::All],
            'manager does not manage departments' => [UserRole::Manager, 'departments.manage', null],
            'unknown ability is denied' => [UserRole::Admin, 'nothing.here', null],
        ];
    }

    #[DataProvider('roleGrants')]
    public function test_global_role_grants(UserRole $role, string $ability, ?DataScope $expected): void
    {
        $user = User::factory()->role($role)->create();

        $this->assertSame($expected, PermissionMap::scopeFor($user, $ability));
    }

    public function test_department_membership_grants_rights_to_staff(): void
    {
        $logistics = User::factory()->staff()->inDepartment('logistics')->create();

        $this->assertSame(DataScope::Department, PermissionMap::scopeFor($logistics, 'customers.view'));
        $this->assertSame(DataScope::All, PermissionMap::scopeFor($logistics, 'catalog.view'));
        $this->assertSame(DataScope::Department, PermissionMap::scopeFor($logistics, 'departments.view'));
        $this->assertNull(PermissionMap::scopeFor($logistics, 'customers.create'));
    }

    public function test_the_widest_grant_wins(): void
    {
        $rep = User::factory()->sales()->inDepartment('sales', DepartmentRole::Head)->create();

        $this->assertSame(DataScope::Department, PermissionMap::scopeFor($rep, 'customers.update'));
        $this->assertSame(DataScope::Department, PermissionMap::scopeFor($rep, 'customers.change_owner'));
    }

    public function test_department_role_must_match(): void
    {
        $viewer = User::factory()->staff()->inDepartment('sales', DepartmentRole::Viewer)->create();

        $this->assertSame(DataScope::All, PermissionMap::scopeFor($viewer, 'customers.view'));
        $this->assertNull(PermissionMap::scopeFor($viewer, 'customers.update'));
        $this->assertNull(PermissionMap::scopeFor($viewer, 'departments.view'));
    }

    public function test_inactive_departments_grant_nothing(): void
    {
        Department::query()->where('code', 'logistics')->update(['is_active' => false]);
        $user = User::factory()->staff()->inDepartment('logistics')->create();

        $this->assertNull(PermissionMap::scopeFor($user, 'catalog.view'));
    }

    public function test_inactive_users_get_nothing(): void
    {
        $manager = User::factory()->manager()->inactive()->create();

        $this->assertNull(PermissionMap::scopeFor($manager, 'customers.view'));
    }

    public function test_portal_users_never_match_internal_grants(): void
    {
        $portal = User::factory()->portal()->create();
        DB::table('users')->where('id', $portal->id)->update(['role' => 'manager']);
        DB::table('department_user')->insert([
            'department_id' => Department::query()->where('code', 'sales')->value('id'),
            'user_id' => $portal->id,
            'role' => 'head',
            'is_primary' => true,
        ]);

        $this->assertNull(PermissionMap::scopeFor($portal->fresh(), 'customers.view'));
    }

    public function test_department_scope_covers_colleagues_but_not_strangers(): void
    {
        $head = User::factory()->staff()->inDepartment('sales', DepartmentRole::Head)->create();
        $colleague = User::factory()->sales()->inDepartment('sales')->create();
        $stranger = User::factory()->sales()->inDepartment('finance')->create();

        $this->assertTrue(PermissionMap::allowsFor($head, 'customers.update', $head->id));
        $this->assertTrue(PermissionMap::allowsFor($head, 'customers.update', $colleague->id));
        $this->assertFalse(PermissionMap::allowsFor($head, 'customers.update', $stranger->id));
        $this->assertFalse(PermissionMap::allowsFor($head, 'customers.update', null));
    }

    public function test_own_scope_covers_only_own_records(): void
    {
        $rep = User::factory()->sales()->create();
        $other = User::factory()->sales()->create();

        $this->assertTrue(PermissionMap::allowsFor($rep, 'customers.update', $rep->id));
        $this->assertFalse(PermissionMap::allowsFor($rep, 'customers.update', $other->id));
    }
}
