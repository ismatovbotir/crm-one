<?php

namespace App\Livewire\Auth;

use App\Enums\UserRole;
use App\Enums\UserType;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use App\Support\DevLoginBypass;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class Login extends Component
{
    /** Failed attempts allowed per email+IP before a lockout. */
    public const MAX_ATTEMPTS_PER_ACCOUNT = 5;

    /** Failed attempts allowed per IP across all emails (password spraying) within the window. */
    public const MAX_ATTEMPTS_PER_IP = 10;

    public const IP_WINDOW_SECONDS = 300;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /**
     * Local development shortcut: any credentials sign in. Never active outside a debug-enabled local environment.
     */
    protected function bypassesCredentialCheck(): bool
    {
        return DevLoginBypass::isAllowed();
    }

    protected function loginWithoutCredentials(): void
    {
        $staff = User::query()->internal()->active();
        $user = (clone $staff)->where('email', $this->email)->first()
            ?? (clone $staff)->where('role', UserRole::Admin)->orderBy('id')->first();

        if ($user === null) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $this->completeLogin($user);
    }

    /**
     * Second factor (roadmap step 6): a user with 2FA is not signed in yet; the password-verified
     * user id waits in the session for the TOTP or recovery code.
     */
    protected function completeLogin(User $user): void
    {
        Auth::guard('portal')->logout(); // Spec 03 4.1: never a staff and a portal session at once.

        if (app(TwoFactor::class)->enabled($user)) {
            session()->regenerate();
            session()->put('two_factor.login', ['id' => $user->id, 'remember' => $this->remember, 'expires' => now()->addMinutes((int) config('security.two_factor_challenge_minutes', 5))->timestamp]);
            $this->redirectRoute('two-factor.challenge', navigate: false);

            return;
        }

        Auth::login($user, $this->remember);
        session()->regenerate();
        $this->recordLogin();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: false);
    }

    public function login(): void
    {
        if ($this->bypassesCredentialCheck()) {
            $this->loginWithoutCredentials();

            return;
        }

        $this->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);
        $this->ensureIsNotRateLimited();

        $credentials = ['email' => $this->email, 'password' => $this->password, 'is_active' => true, 'type' => UserType::Internal->value];
        $authenticated = Auth::validate($credentials);

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey(), self::IP_WINDOW_SECONDS);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        $this->completeLogin(User::query()->internal()->active()->where('email', $this->email)->firstOrFail());
    }

    protected function recordLogin(): void
    {
        Auth::user()?->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    /**
     * Two limiters: email+IP (brute force on one account) and IP only (one password sprayed
     * across many accounts). The IP counter is not cleared by a successful login because
     * the attempts it counts belong to other accounts.
     */
    protected function ensureIsNotRateLimited(): void
    {
        $key = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS_PER_ACCOUNT) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->ipThrottleKey(), self::MAX_ATTEMPTS_PER_IP) => $this->ipThrottleKey(),
            default => null,
        };

        if ($key === null) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    protected function ipThrottleKey(): string
    {
        return 'login-ip|'.request()->ip();
    }

    public function render(): View
    {
        return view('livewire.auth.login')->title(__('auth.sign_in'));
    }
}
