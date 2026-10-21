<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Second login step (roadmap step 6): the 6-digit code from the authenticator app or a recovery
 * code. The password-verified user waits in the session for a few minutes; 5 wrong codes per user
 * and minute are allowed; then the login starts over.
 */
#[Layout('layouts.guest')]
class TwoFactorChallenge extends Component
{
    public string $code = '';

    public function mount(): void
    {
        if ($this->pending() === null) {
            $this->redirectRoute('login', navigate: false);
        }
    }

    /**
     * @return array{id: int, remember: bool, expires: int}|null
     */
    protected function pending(): ?array
    {
        $pending = session('two_factor.login');

        if (! is_array($pending) || ($pending['expires'] ?? 0) < now()->timestamp) {
            session()->forget('two_factor.login');

            return null;
        }

        return $pending;
    }

    public function verify(TwoFactor $twoFactor): void
    {
        $pending = $this->pending();

        if ($pending === null) {
            $this->redirectRoute('login', navigate: false);

            return;
        }

        $this->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => __('auth.two_factor.code')]);
        $key = 'two-factor|'.$pending['id'];

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', __('auth.two_factor.throttled', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        $user = User::query()->internal()->active()->find($pending['id']);

        if ($user === null || ! $twoFactor->verify($user, $this->code)) {
            RateLimiter::hit($key, 60);
            $this->addError('code', __('auth.two_factor.invalid'));
            $this->code = '';

            return;
        }

        RateLimiter::clear($key);
        session()->forget('two_factor.login');
        Auth::guard('portal')->logout();
        Auth::login($user, (bool) $pending['remember']);
        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.auth.two-factor-challenge')->title(__('auth.two_factor.title'));
    }
}
