<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\TwoFactorSettings;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\AuthEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Roadmap step 6: staff TOTP 2FA with recovery codes, the login challenge, no code reuse, and
 * enforcement for admin, accountant and finance by setting.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_matches_rfc_6238_reference_values(): void
    {
        // RFC 6238 appendix B, SHA1 secret "12345678901234567890" (base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ), last 6 digits.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::code($secret, Totp::step(59)));
        $this->assertSame('081804', Totp::code($secret, Totp::step(1111111109)));
        $this->assertSame('005924', Totp::code($secret, Totp::step(1234567890)));
        $this->assertSame(32, strlen(Totp::generateSecret()));
        $this->assertNull(Totp::verify($secret, '12345', 1, 59));
    }

    /**
     * @return array{0: User, 1: string, 2: list<string>}
     */
    private function enrolled(): array
    {
        $user = User::factory()->accountant()->create(['email' => 'acc@crm.test', 'password' => 'Secret-pass-1']);
        $secret = Totp::generateSecret();
        $codes = app(TwoFactor::class)->confirm($user, $secret, Totp::code($secret, Totp::step(now()->timestamp - 30)));

        return [$user->fresh(), $secret, $codes];
    }

    public function test_setup_from_the_cabinet_with_recovery_codes(): void
    {
        $user = User::factory()->accountant()->create();

        $component = Livewire::actingAs($user)->test(TwoFactorSettings::class)->call('start')->assertSee('data-two-factor-secret', false);
        $secret = (string) session('two_factor.setup_secret');
        $component->set('code', '000000')->call('confirm')->assertHasErrors('code');
        $component->set('code', Totp::code($secret, Totp::step()))->call('confirm')->assertHasNoErrors()->assertSee('data-recovery-codes', false);

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertStringNotContainsString($secret, (string) $user->getRawOriginal('two_factor_secret'), 'The secret is stored encrypted.');
        $this->assertCount(8, $component->get('recoveryCodes'));
        $this->assertTrue(AuthEvent::query()->where('event', '2fa_enabled')->exists());
    }

    public function test_login_requires_the_second_factor_and_codes_are_single_use(): void
    {
        [$user, $secret, $codes] = $this->enrolled();

        Livewire::test(Login::class)->set('email', 'acc@crm.test')->set('password', 'Secret-pass-1')->call('login')->assertRedirect(route('two-factor.challenge'));
        $this->assertFalse(Auth::check(), 'Not signed in before the second factor.');

        $code = Totp::code($secret, Totp::step());
        Livewire::test(TwoFactorChallenge::class)->set('code', $code)->call('verify')->assertRedirect(route('dashboard', absolute: false));
        $this->assertSame($user->id, Auth::id());

        // The same code cannot be used again (replay).
        Auth::logout();
        session()->put('two_factor.login', ['id' => $user->id, 'remember' => false, 'expires' => now()->addMinutes(5)->timestamp]);
        Livewire::test(TwoFactorChallenge::class)->set('code', $code)->call('verify')->assertHasErrors('code');

        // A recovery code works once.
        Livewire::test(TwoFactorChallenge::class)->set('code', $codes[0])->call('verify')->assertRedirect();
        Auth::logout();
        session()->put('two_factor.login', ['id' => $user->id, 'remember' => false, 'expires' => now()->addMinutes(5)->timestamp]);
        Livewire::test(TwoFactorChallenge::class)->set('code', $codes[0])->call('verify')->assertHasErrors('code');
        $this->assertSame(7, app(TwoFactor::class)->remainingRecoveryCodes($user->fresh()));
    }

    public function test_challenge_needs_a_pending_login_and_is_rate_limited(): void
    {
        Livewire::test(TwoFactorChallenge::class)->assertRedirect(route('login'));

        [$user] = $this->enrolled();
        session()->put('two_factor.login', ['id' => $user->id, 'remember' => false, 'expires' => now()->addMinutes(5)->timestamp]);

        foreach (range(1, 5) as $attempt) {
            Livewire::test(TwoFactorChallenge::class)->set('code', '111111')->call('verify')->assertHasErrors('code');
        }

        Livewire::test(TwoFactorChallenge::class)->set('code', '111111')->call('verify')->assertSee(__('auth.two_factor.throttled', ['seconds' => 60]), false);
    }

    public function test_enforcement_for_required_roles(): void
    {
        $accountant = User::factory()->accountant()->create();
        $sales = User::factory()->sales()->create();

        $this->actingAs($accountant)->get(route('dashboard'))->assertOk(); // Off by default.

        Setting::putMany(['security.enforce_two_factor' => '1']);
        $this->actingAs($accountant)->get(route('dashboard'))->assertRedirect(route('cabinet'));
        $this->actingAs($accountant)->get(route('cabinet'))->assertOk()->assertSee(__('auth.two_factor.required'));
        $this->actingAs($sales)->get(route('dashboard'))->assertOk();

        [$enrolled] = $this->enrolled();
        $this->actingAs($enrolled)->get(route('dashboard'))->assertOk();
        Livewire::actingAs($enrolled)->test(TwoFactorSettings::class)->set('code', '123456')->call('disable')->assertHasErrors('code');
        $this->assertNotNull($enrolled->fresh()->two_factor_confirmed_at, 'A required 2FA cannot be switched off.');
    }

    /** Roadmap step 10: an admin resets another user's 2FA (lost phone); never their own; audited. */
    public function test_admin_can_reset_another_users_two_factor(): void
    {
        [$user] = $this->enrolled();
        $admin = User::factory()->admin()->create();
        $finance = User::factory()->finance()->create();

        $this->assertFalse($finance->can('resetTwoFactor', $user));
        $this->assertTrue(app(TwoFactor::class)->enabled($user->fresh()));

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->assertSee(__('users.two_factor_reset'))
            ->call('resetTwoFactor', $user->id)
            ->assertSee(__('users.two_factor_reset_done', ['name' => $user->name]));

        $this->assertFalse(app(TwoFactor::class)->enabled($user->fresh()));
        $this->assertDatabaseHas('activity_log', ['subject_id' => $user->id, 'event' => 'two_factor_reset']);
        $this->assertFalse($admin->can('resetTwoFactor', $admin));
    }
}
