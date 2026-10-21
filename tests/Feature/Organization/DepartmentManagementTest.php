<?php

namespace Tests\Feature\Organization;

use App\Enums\DepartmentRole;
use App\Livewire\Departments\Form;
use App\Livewire\Departments\Index;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    private function department(string $code): Department
    {
        return Department::query()->where('code', $code)->firstOrFail();
    }

    public function test_standard_departments_are_seeded_with_translated_names(): void
    {
        $this->assertSame(
            ['sales', 'purchase', 'logistics', 'finance', 'management', 'it', 'support', 'warehouse', 'service'],
            Department::query()->orderBy('sort_order')->pluck('code')->all(),
        );

        app()->setLocale('ru');
        $this->assertSame('Импорт и транспорт', $this->department('logistics')->displayName());
    }

    public function test_admin_creates_a_department_with_names_per_language(): void
    {
        Livewire::test(Form::class)
            ->set('code', ' Quality ')
            ->set('names.uz', 'Sifat nazorati')
            ->set('names.ru', 'Контроль качества')
            ->set('sort_order', 80)
            ->call('save')
            ->assertHasNoErrors();

        $department = $this->department('quality');
        $this->assertSame('Sifat nazorati', $department->name['uz']);
        $this->assertNull($department->name['en']);
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'department', 'subject_id' => $department->id, 'event' => 'created', 'causer_id' => $this->admin->id]);
    }

    public function test_department_validation(): void
    {
        $archived = Department::factory()->create(['code' => 'old-dept']);
        $archived->delete();

        Livewire::test(Form::class)
            ->set('code', 'old-dept')
            ->set('names.uz', '')
            ->call('save')
            ->assertHasErrors(['code' => 'unique', 'names.uz' => 'required']);

        Livewire::test(Form::class)
            ->set('code', 'bad code!')
            ->set('names.uz', 'X')
            ->call('save')
            ->assertHasErrors(['code' => 'regex']);
    }

    public function test_a_department_cannot_be_its_own_parent(): void
    {
        $sales = $this->department('sales');

        Livewire::test(Form::class, ['department' => $sales])
            ->set('parent_id', $sales->id)
            ->call('save')
            ->assertHasErrors(['parent_id']);
    }

    public function test_admin_adds_a_member_and_the_change_is_logged(): void
    {
        $logistics = $this->department('logistics');
        $user = User::factory()->staff()->create(['name' => 'Lola']);

        Livewire::test(Form::class, ['department' => $logistics])
            ->set('memberUserId', $user->id)
            ->set('memberRole', 'head')
            ->call('addMember')
            ->assertHasNoErrors();

        $membership = DepartmentMember::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(DepartmentRole::Head, $membership->role);
        $this->assertTrue($membership->is_primary, 'The first membership becomes primary.');

        $log = $logistics->activities()->first();
        $this->assertSame('member_added', $log->event);
        $this->assertSame('Lola', $log->properties['new']['user']);
    }

    /** BR-O.1 */
    public function test_a_department_has_exactly_one_head(): void
    {
        $logistics = $this->department('logistics');
        User::factory()->staff()->inDepartment('logistics', DepartmentRole::Head)->create(['name' => 'First Head']);
        $second = User::factory()->staff()->create();

        Livewire::test(Form::class, ['department' => $logistics])
            ->set('memberUserId', $second->id)
            ->set('memberRole', 'head')
            ->call('addMember')
            ->assertHasErrors(['memberUserId']);

        $this->assertSame(1, DepartmentMember::query()->where('department_id', $logistics->id)->where('role', 'head')->count());
        $this->assertFalse(DepartmentMember::query()->where('user_id', $second->id)->exists());
    }

    /** BR-O.1 */
    public function test_a_user_has_one_primary_department(): void
    {
        $user = User::factory()->staff()->inDepartment('logistics')->create();
        $purchase = $this->department('purchase');

        Livewire::test(Form::class, ['department' => $purchase])
            ->set('memberUserId', $user->id)
            ->set('memberRole', 'specialist')
            ->set('memberPrimary', true)
            ->call('addMember')
            ->assertHasNoErrors();

        $primaries = DepartmentMember::query()->where('user_id', $user->id)->where('is_primary', true)->get();
        $this->assertCount(1, $primaries);
        $this->assertSame($purchase->id, $primaries->first()->department_id);
    }

    /** BR-O.1 */
    public function test_portal_users_cannot_become_members(): void
    {
        $portal = User::factory()->portal()->create();

        Livewire::test(Form::class, ['department' => $this->department('sales')])
            ->set('memberUserId', $portal->id)
            ->call('addMember')
            ->assertHasErrors(['memberUserId']);

        $this->assertFalse(DepartmentMember::query()->where('user_id', $portal->id)->exists());
    }

    public function test_changing_role_and_removing_a_member(): void
    {
        $user = User::factory()->staff()->inDepartment('logistics')->inDepartment('purchase', primary: false)->create();
        $logistics = $this->department('logistics');

        Livewire::test(Form::class, ['department' => $logistics])
            ->call('changeMemberRole', $user->id, 'viewer')
            ->assertHasNoErrors();

        $this->assertSame(DepartmentRole::Viewer, DepartmentMember::query()->where('user_id', $user->id)->where('department_id', $logistics->id)->firstOrFail()->role);

        Livewire::test(Form::class, ['department' => $logistics])->call('removeMember', $user->id);

        $this->assertFalse(DepartmentMember::query()->where('user_id', $user->id)->where('department_id', $logistics->id)->exists());
        $remaining = DepartmentMember::query()->where('user_id', $user->id)->where('department_id', $this->department('purchase')->id)->firstOrFail();
        $this->assertTrue($remaining->is_primary, 'The remaining membership becomes primary.');
        $this->assertSame(['member_removed', 'member_changed'], $logistics->activities()->pluck('event')->take(2)->all());
    }

    public function test_index_search_and_status_filter(): void
    {
        $this->department('support')->update(['is_active' => false]);

        Livewire::test(Index::class)
            ->set('search', 'logist')
            ->assertSee(__('departments.names.logistics'))
            ->assertDontSee(__('departments.names.finance'))
            ->set('search', '')
            ->set('status', 'inactive')
            ->assertSee(__('departments.names.support'))
            ->assertDontSee(__('departments.names.sales'));
    }
}
