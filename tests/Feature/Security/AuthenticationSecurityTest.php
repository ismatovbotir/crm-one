<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsureUserIsActive;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Login, dev bypass guard, session handling, password reset, deactivated users (ASVS V2/V3).
 */
class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The "any credentials" dev bypass must need BOTH APP_ENV=local AND APP_DEBUG=true.
     */
    #[DataProvider('nonBypassEnvironments')]
    public function test_dev_bypass_is_unreachable_outside_local_debug(string $env, bool $debug): void
    {
        $this->app['env'] = $env;
        config(['app.debug' => $debug]);
        User::factory()->admin()->create();

        Livewire::test(Login::class)
            ->set('email', 'attacker@example.com')
            ->set('password', 'anything')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public static function nonBypassEnvironments(): array
    {
        return [
            'production, debug off' => ['production', false],
            'production, debug on' => ['production', true],
            'staging, debug on' => ['staging', true],
            'testing, debug on' => ['testing', true],
            'local, debug off' => ['local', false],
        ];
    }

    public function test_dev_bypass_never_picks_portal_or_inactive_accounts(): void
    {
        $this->app['env'] = 'local';
        config(['app.debug' => true]);
        $portal = User::factory()->portal()->create();
        $inactive = User::factory()->admin()->inactive()->create();

        foreach ([$portal->email, $inactive->email] as $email) {
            Livewire::test(Login::class)->set('email', $email)->set('password', 'x')->call('login');
            $this->assertGuest();
        }
    }

    public function test_portal_users_cannot_sign_in_to_the_staff_realm(): void
    {
        $portal = User::factory()->portal()->create();

        Livewire::test(Login::class)
            ->set('email', $portal->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_session_id_is_regenerated_on_login(): void
    {
        $user = User::factory()->create();
        $this->startSession();
        $before = session()->getId();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertNotSame($before, session()->getId(), 'Session fixation: id not regenerated');
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_deactivated_user_is_cut_off_on_next_page_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_realm_and_activity_checks_are_persistent_livewire_middleware(): void
    {
        $persistent = Livewire::getPersistentMiddleware();

        $this->assertContains(EnsureUserIsActive::class, $persistent);
        $this->assertContains(EnsureInternalUser::class, $persistent);
    }

    public function test_session_cookie_is_http_only_and_same_site(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertContains(config('session.same_site'), ['lax', 'strict']);
    }

    public function test_forgot_password_gives_the_same_answer_for_unknown_and_known_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = Livewire::test(ForgotPassword::class)->set('email', $user->email)->call('sendPasswordResetLink');
        $unknown = Livewire::test(ForgotPassword::class)->set('email', 'nobody@example.com')->call('sendPasswordResetLink');

        $known->assertHasNoErrors();
        $unknown->assertHasNoErrors();
    }

    public function test_reset_token_of_one_user_cannot_reset_another_users_password(): void
    {
        $victim = User::factory()->admin()->create();
        $attacker = User::factory()->create();
        $token = Password::createToken($attacker);

        Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', $victim->email)
            ->set('password', 'N3w-Passw0rd!x')
            ->set('password_confirmation', 'N3w-Passw0rd!x')
            ->call('resetPassword')
            ->assertHasErrors('email');

        $this->assertTrue(Hash::check('password', $victim->fresh()->password));
    }

    public function test_reset_token_is_single_use_and_does_not_sign_in(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        foreach ([true, false] as $first) {
            $component = Livewire::test(ResetPassword::class, ['token' => $token])
                ->set('email', $user->email)
                ->set('password', 'N3w-Passw0rd!x')
                ->set('password_confirmation', 'N3w-Passw0rd!x')
                ->call('resetPassword');

            $first ? $component->assertHasNoErrors() : $component->assertHasErrors('email');
        }

        $this->assertGuest();
    }

    public function test_reset_token_is_locked_against_client_tampering(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(ResetPassword::class, ['token' => 'abc'])->set('token', 'other');
    }

    public function test_reset_link_notification_is_throttled_per_account(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        Livewire::test(ForgotPassword::class)->set('email', $user->email)->call('sendPasswordResetLink');
        Livewire::test(ForgotPassword::class)->set('email', $user->email)->call('sendPasswordResetLink');

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    }

    /**
     * FINDING (Low): the login throttle key is email|ip only, so one IP can spray one
     * password across many accounts without ever being throttled.
     */
    public function test_password_spraying_from_one_ip_is_throttled(): void
    {
        $users = User::factory()->count(12)->create();

        foreach ($users->take(11) as $user) {
            Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Winter2026!')->call('login');
        }

        Livewire::test(Login::class)
            ->set('email', $users->last()->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_admin_role_is_needed_even_with_valid_session_for_admin_pages(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Sales)->create());

        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('settings.appearance'))->assertForbidden();
    }
}
