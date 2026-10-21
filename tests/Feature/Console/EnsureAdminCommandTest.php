<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `php artisan crm:admin`: the default administrator from ADMIN_* (config crm.admin).
 */
class EnsureAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private function configure(?string $email = 'root@example.com', ?string $password = 'S3cret-pass', string $name = 'Root Admin'): void
    {
        config(['crm.admin' => ['name' => $name, 'email' => $email, 'password' => $password]]);
    }

    public function test_it_creates_the_administrator_with_id_one(): void
    {
        $this->configure();

        $this->artisan('crm:admin')->expectsOutputToContain('Administrator created: #1 Root Admin <root@example.com>')->assertExitCode(0);

        $user = User::query()->findOrFail(1);
        $this->assertSame([UserRole::Admin, true, 'root@example.com'], [$user->role, $user->is_active, $user->email]);
        $this->assertTrue(Hash::check('S3cret-pass', $user->password));
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_an_existing_user_with_the_email_gets_the_password_reset_and_admin_access_back(): void
    {
        $user = User::factory()->sales()->create(['email' => 'root@example.com', 'is_active' => false, 'password' => 'old-password']);
        $this->configure('Root@Example.com');

        $this->artisan('crm:admin')->expectsOutputToContain('Password reset for #'.$user->id)->assertExitCode(0);

        $user->refresh();
        $this->assertTrue(Hash::check('S3cret-pass', $user->password));
        $this->assertSame([UserRole::Admin, true], [$user->role, $user->is_active]);
        $this->assertSame(1, User::query()->count());
    }

    public function test_id_one_belonging_to_someone_else_is_never_overwritten(): void
    {
        $other = User::factory()->sales()->create(['id' => 1, 'email' => 'someone@example.com']);
        $this->configure();

        $this->artisan('crm:admin')->expectsOutputToContain('User id 1 already belongs to someone else')->assertExitCode(0);

        $this->assertSame('someone@example.com', $other->fresh()->email);
        $this->assertSame(UserRole::Admin, User::query()->where('email', 'root@example.com')->sole()->role);
    }

    public function test_it_refuses_without_a_valid_email_or_password(): void
    {
        $this->configure(null);
        $this->artisan('crm:admin')->expectsOutputToContain('ADMIN_EMAIL')->assertExitCode(1);

        $this->configure('root@example.com', 'short');
        $this->artisan('crm:admin')->expectsOutputToContain('ADMIN_PASSWORD')->assertExitCode(1);
        $this->assertSame(0, User::query()->count());
    }
}
