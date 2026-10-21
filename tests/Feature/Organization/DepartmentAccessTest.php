<?php

namespace Tests\Feature\Organization;

use App\Enums\DepartmentRole;
use App\Enums\UserRole;
use App\Livewire\Departments\Form;
use App\Models\Department;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spec 03 section 4.10, row "Departments & memberships CRUD":
 * admin yes, manager view, department heads/specialists view own, others no.
 */
class DepartmentAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Closure(): User, bool}>
     */
    public static function listAccess(): array
    {
        return [
            'admin' => [fn () => User::factory()->admin()->create(), true],
            'manager' => [fn () => User::factory()->manager()->create(), true],
            'logistics head' => [fn () => User::factory()->staff()->inDepartment('logistics', DepartmentRole::Head)->create(), true],
            'purchase specialist' => [fn () => User::factory()->staff()->inDepartment('purchase')->create(), true],
            'sales without department' => [fn () => User::factory()->sales()->create(), false],
            'finance without department' => [fn () => User::factory()->finance()->create(), false],
            'department viewer' => [fn () => User::factory()->staff()->inDepartment('support', DepartmentRole::Viewer)->create(), false],
            'staff without department' => [fn () => User::factory()->staff()->create(), false],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('listAccess')]
    public function test_department_list_access(Closure $makeUser, bool $allowed): void
    {
        $response = $this->actingAs($makeUser())->get(route('departments.index'));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_specialist_sees_only_own_departments(): void
    {
        $specialist = User::factory()->staff()->inDepartment('logistics')->create();
        $logistics = Department::query()->where('code', 'logistics')->firstOrFail();
        $finance = Department::query()->where('code', 'finance')->firstOrFail();

        $this->actingAs($specialist);

        $this->get(route('departments.show', $logistics))->assertOk()->assertDontSee(__('departments.add_member'));
        $this->get(route('departments.show', $finance))->assertForbidden();
        $this->get(route('departments.index'))
            ->assertSee(__('departments.names.logistics'))
            ->assertDontSee(__('departments.names.finance'));
    }

    public function test_manager_views_all_departments_read_only(): void
    {
        $manager = User::factory()->manager()->create();
        $sales = Department::query()->where('code', 'sales')->firstOrFail();
        $member = User::factory()->sales()->create();

        $this->actingAs($manager);

        $this->get(route('departments.show', $sales))->assertOk();
        $this->get(route('departments.create'))->assertForbidden();

        Livewire::test(Form::class, ['department' => $sales])
            ->set('sort_order', 999)
            ->call('save')
            ->assertForbidden();

        Livewire::test(Form::class, ['department' => $sales])
            ->set('memberUserId', $member->id)
            ->call('addMember')
            ->assertForbidden();

        $this->assertNotSame(999, $sales->fresh()->sort_order);
        $this->assertFalse($sales->members()->whereKey($member->id)->exists());
    }

    public function test_navigation_shows_departments_only_to_allowed_users(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(route('dashboard'))
            ->assertSee(route('departments.index'), false);

        $this->actingAs(User::factory()->role(UserRole::Sales)->create())
            ->get(route('dashboard'))
            ->assertDontSee(route('departments.index'), false);
    }
}
