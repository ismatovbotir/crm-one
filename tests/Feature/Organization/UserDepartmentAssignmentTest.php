<?php

namespace Tests\Feature\Organization;

use App\Enums\DepartmentRole;
use App\Enums\UserRole;
use App\Livewire\Users\Form;
use App\Livewire\Users\Index;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Users admin page extended with type, department and the staff role (Spec 03 section 9).
 */
class UserDepartmentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->value('id');
    }

    public function test_staff_user_requires_a_primary_department(): void
    {
        Livewire::test(Form::class)
            ->set('name', 'Lola Logistics')
            ->set('email', 'lola@crm.test')
            ->set('role', 'staff')
            ->set('password', 'secure-password-1')
            ->set('password_confirmation', 'secure-password-1')
            ->call('save')
            ->assertHasErrors(['departmentId' => 'required']);

        $this->assertDatabaseMissing('users', ['email' => 'lola@crm.test']);
    }

    public function test_admin_creates_staff_user_in_a_department(): void
    {
        Livewire::test(Form::class)
            ->set('name', 'Lola Logistics')
            ->set('email', 'lola@crm.test')
            ->set('role', 'staff')
            ->set('position', 'Logistics specialist')
            ->set('departmentId', $this->departmentId('logistics'))
            ->set('departmentRole', 'specialist')
            ->set('password', 'secure-password-1')
            ->set('password_confirmation', 'secure-password-1')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('users.index'));

        $user = User::query()->where('email', 'lola@crm.test')->firstOrFail();
        $this->assertSame(UserRole::Staff, $user->role);
        $this->assertSame('Logistics specialist', $user->position);
        $this->assertSame('logistics', $user->primaryDepartment()?->code);
        $this->assertSame(DepartmentRole::Specialist, $user->primaryDepartment()->membership->role);
    }

    public function test_head_conflict_rolls_back_user_creation(): void
    {
        User::factory()->staff()->inDepartment('logistics', DepartmentRole::Head)->create();

        Livewire::test(Form::class)
            ->set('name', 'Second Head')
            ->set('email', 'second@crm.test')
            ->set('role', 'staff')
            ->set('departmentId', $this->departmentId('logistics'))
            ->set('departmentRole', 'head')
            ->set('password', 'secure-password-1')
            ->set('password_confirmation', 'secure-password-1')
            ->call('save')
            ->assertHasErrors(['departmentId']);

        $this->assertDatabaseMissing('users', ['email' => 'second@crm.test']);
    }

    public function test_changing_primary_department_on_edit_keeps_one_primary(): void
    {
        $user = User::factory()->sales()->inDepartment('sales')->create();

        Livewire::test(Form::class, ['user' => $user])
            ->assertSet('departmentId', $this->departmentId('sales'))
            ->set('departmentId', $this->departmentId('support'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, DepartmentMember::query()->where('user_id', $user->id)->count());
        $this->assertSame(
            [$this->departmentId('support')],
            DepartmentMember::query()->where('user_id', $user->id)->where('is_primary', true)->pluck('department_id')->all(),
        );
    }

    public function test_portal_user_edit_has_no_role_or_password(): void
    {
        $portal = User::factory()->portal()->create(['name' => 'Buyer']);

        Livewire::test(Form::class, ['user' => $portal])
            ->assertSet('type', 'portal')
            ->set('name', 'Buyer Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $portal->refresh();
        $this->assertSame('Buyer Renamed', $portal->name);
        $this->assertNull($portal->role);
        $this->assertNull($portal->password);
    }

    public function test_users_list_filters_by_type_and_department(): void
    {
        User::factory()->staff()->inDepartment('logistics')->create(['name' => 'Lola Logistics']);
        User::factory()->finance()->create(['name' => 'Fiona Finance']);
        User::factory()->portal()->create(['name' => 'Bob Buyer']);

        Livewire::test(Index::class)
            ->set('type', 'portal')
            ->assertSee('Bob Buyer')
            ->assertDontSee('Fiona Finance')
            ->set('type', '')
            ->set('department', (string) $this->departmentId('logistics'))
            ->assertSee('Lola Logistics')
            ->assertDontSee('Fiona Finance')
            ->assertDontSee('Bob Buyer');
    }

    public function test_deactivation_is_stamped_and_logged(): void
    {
        $user = User::factory()->sales()->create();

        Livewire::test(Index::class)->call('toggleActive', $user->id);

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertNotNull($user->deactivated_at);
        $this->assertSame($this->admin->id, $user->deactivated_by);
        $this->assertSame('deactivated', $user->activities()->first()->event);
    }

    public function test_role_change_is_logged(): void
    {
        $user = User::factory()->sales()->create();

        Livewire::test(Form::class, ['user' => $user])->set('role', 'manager')->call('save');

        $log = $user->activities()->first();
        $this->assertSame('role_changed', $log->event);
        $this->assertSame(['role' => 'sales'], $log->properties['old']);
        $this->assertSame(['role' => 'manager'], $log->properties['new']);
        $this->assertSame($this->admin->id, $log->causer_id);
    }
}
