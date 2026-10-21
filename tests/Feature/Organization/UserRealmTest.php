<?php

namespace Tests\Feature\Organization;

use App\Enums\UserRole;
use App\Enums\UserType;
use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class UserRealmTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_users_default_to_the_internal_realm(): void
    {
        $user = User::factory()->sales()->create();

        $this->assertSame(UserType::Internal, $user->fresh()->type);
        $this->assertTrue($user->isInternal());
    }

    /** Spec 03 AC-6 */
    public function test_portal_user_with_a_role_forced_in_the_database_has_no_role_rights(): void
    {
        $portal = User::factory()->portal()->create();
        DB::table('users')->where('id', $portal->id)->update(['role' => UserRole::Admin->value]);
        $portal->refresh();

        $this->assertSame(UserRole::Admin, $portal->role);
        $this->assertFalse($portal->isAdmin());
        $this->assertFalse($portal->hasRole(UserRole::Admin));
        $this->assertFalse($portal->can('viewAny', User::class));
    }

    public function test_portal_user_session_cannot_reach_internal_screens(): void
    {
        $portal = User::factory()->portal()->create();

        $this->actingAs($portal)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_saving_a_portal_user_clears_any_role(): void
    {
        $portal = User::factory()->portal()->create();
        $portal->role = UserRole::Manager;
        $portal->save();

        $this->assertNull($portal->fresh()->role);
        $this->assertNull($portal->fresh()->password);
    }

    public function test_internal_user_without_role_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        User::factory()->create(['role' => null]);
    }

    public function test_portal_user_cannot_sign_in_on_the_staff_login(): void
    {
        $portal = User::factory()->portal()->create(['email' => 'buyer@client.uz']);
        DB::table('users')->where('id', $portal->id)->update(['password' => bcrypt('known-password')]);

        Livewire::test(Login::class)
            ->set('email', 'buyer@client.uz')
            ->set('password', 'known-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_dev_login_bypass_never_picks_a_portal_user(): void
    {
        $this->app['env'] = 'local';
        config(['app.debug' => true]);
        $portal = User::factory()->portal()->create(['email' => 'buyer@client.uz']);
        $admin = User::factory()->admin()->create();

        Livewire::test(Login::class)
            ->set('email', $portal->email)
            ->set('password', 'anything')
            ->call('login');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_successful_login_records_last_login_time(): void
    {
        $user = User::factory()->sales()->create(['email' => 'rep@crm.test']);

        Livewire::test(Login::class)
            ->set('email', 'rep@crm.test')
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertNotNull($user->fresh()->last_login_at);
    }
}
