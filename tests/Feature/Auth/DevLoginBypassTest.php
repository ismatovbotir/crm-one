<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DevLoginBypassTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_credentials_sign_in_as_admin_in_local_debug_environment(): void
    {
        $this->app['env'] = 'local';
        config(['app.debug' => true]);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        Livewire::test(Login::class)
            ->set('email', 'whatever')
            ->set('password', 'nonsense')
            ->call('login')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_bad_credentials_are_rejected_outside_local_environment(): void
    {
        config(['app.debug' => true]);
        User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        Livewire::test(Login::class)
            ->set('email', 'nobody@example.com')
            ->set('password', 'nonsense')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }
}
