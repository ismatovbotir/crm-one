<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Livewire\Users\Form;
use App\Livewire\Users\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    /** AC-0.3 (pages) */
    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_get_403_on_user_pages(UserRole $role): void
    {
        $actor = User::factory()->role($role)->create();
        $other = User::factory()->create();

        $this->actingAs($actor);

        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('users.create'))->assertForbidden();
        $this->get(route('users.edit', $other))->assertForbidden();
    }

    /** AC-0.3 (actions) */
    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_cannot_call_user_actions(UserRole $role): void
    {
        $actor = User::factory()->role($role)->create();
        $other = User::factory()->create();

        $this->actingAs($actor);

        Livewire::test(Index::class)->assertForbidden();
        Livewire::test(Form::class)->assertForbidden();
        Livewire::test(Form::class, ['user' => $other])->assertForbidden();

        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_admin_can_view_users_list(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->finance()->create(['name' => 'Fiona Finance']);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Fiona Finance')
            ->assertSee($other->email);
    }

    public function test_users_list_can_be_filtered(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Alice Admin']);
        User::factory()->finance()->create(['name' => 'Fiona Finance']);
        User::factory()->sales()->inactive()->create(['name' => 'Sid Sales']);

        $this->actingAs($admin);

        Livewire::test(Index::class)
            ->set('role', 'finance')
            ->assertSee('Fiona Finance')
            ->assertDontSee('Sid Sales')
            ->set('role', '')
            ->set('status', 'inactive')
            ->assertSee('Sid Sales')
            ->assertDontSee('Fiona Finance')
            ->set('status', '')
            ->set('search', 'alice')
            ->assertSee('Alice Admin')
            ->assertDontSee('Sid Sales');
    }

    public function test_admin_can_create_user(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Form::class)
            ->set('name', 'New Rep')
            ->set('email', 'New.Rep@Example.com')
            ->set('role', 'sales')
            ->set('password', 'secure-password-1')
            ->set('password_confirmation', 'secure-password-1')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('users.index'));

        $user = User::where('email', 'new.rep@example.com')->firstOrFail();
        $this->assertSame(UserRole::Sales, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('secure-password-1', $user->password));
    }

    public function test_create_user_validates_input(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(Form::class)
            ->set('name', '')
            ->set('email', 'taken@example.com')
            ->set('role', 'superuser')
            ->set('password', 'short')
            ->set('password_confirmation', 'different')
            ->call('save')
            ->assertHasErrors(['name', 'email', 'role', 'password']);

        $this->assertSame(2, User::count());
    }

    public function test_admin_can_change_another_users_role(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $user = User::factory()->sales()->create();
        $originalPassword = $user->password;

        Livewire::test(Form::class, ['user' => $user])
            ->assertSet('role', 'sales')
            ->set('role', 'manager')
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame(UserRole::Manager, $user->role);
        $this->assertSame($originalPassword, $user->password, 'Blank password must not change the password.');
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(Form::class, ['user' => $admin])
            ->set('role', 'sales')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_admin_can_edit_own_name_without_touching_role(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(Form::class, ['user' => $admin])
            ->set('name', 'Renamed Admin')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed Admin', $admin->fresh()->name);
    }

    public function test_admin_can_deactivate_and_reactivate_a_user(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $user = User::factory()->create();

        Livewire::test(Index::class)->call('toggleActive', $user->id)->assertOk();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        Livewire::test(Index::class)->call('toggleActive', $user->id)->assertOk();
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(Index::class)->call('toggleActive', $admin->id)->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'manager' => [UserRole::Manager],
            'sales' => [UserRole::Sales],
            'finance' => [UserRole::Finance],
        ];
    }
}
