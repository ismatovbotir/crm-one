<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_every_role_can_open_the_dashboard(UserRole $role): void
    {
        $user = User::factory()->role($role)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($user->name);
    }

    public function test_root_redirects_authenticated_user_to_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect('/dashboard');
    }

    public function test_users_link_is_only_shown_to_admins(): void
    {
        $this->actingAs(User::factory()->sales()->create())
            ->get(route('dashboard'))
            ->assertDontSee(route('users.index'));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertSee(route('users.index'));
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function roles(): array
    {
        $roles = [];

        foreach (UserRole::cases() as $role) {
            $roles[$role->value] = [$role];
        }

        return $roles;
    }
}
