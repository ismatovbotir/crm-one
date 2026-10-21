<?php

namespace App\Livewire;

use App\Services\Auth\TwoFactor;
use App\Support\Totp;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "My profile" two-factor section for staff (roadmap step 6): set up with an authenticator app
 * (secret shown as text and otpauth link; no QR service is called), confirm with a first code,
 * recovery codes shown once; regenerate codes and switch off need a current code; switching off is
 * not possible while 2FA is required for the role.
 */
class TwoFactorSettings extends Component
{
    public string $code = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public function start(): void
    {
        abort_unless(auth()->user()->isInternal(), 403);
        session()->put('two_factor.setup_secret', Totp::generateSecret());
        $this->reset('code', 'recoveryCodes');
    }

    public function confirm(TwoFactor $twoFactor): void
    {
        $secret = (string) session('two_factor.setup_secret');
        $this->validate(['code' => ['required', 'digits:6']], [], ['code' => __('auth.two_factor.code')]);

        if ($secret === '' || ($codes = $twoFactor->confirm(auth()->user(), $secret, $this->code)) === null) {
            $this->addError('code', __('auth.two_factor.invalid'));

            return;
        }

        session()->forget('two_factor.setup_secret');
        $this->recoveryCodes = $codes;
        $this->code = '';
        session()->flash('status', __('auth.two_factor.enabled'));
    }

    public function regenerate(TwoFactor $twoFactor): void
    {
        if (! $this->checkCode($twoFactor)) {
            return;
        }

        $this->recoveryCodes = $twoFactor->regenerateRecoveryCodes(auth()->user());
        session()->flash('status', __('auth.two_factor.codes_regenerated'));
    }

    public function disable(TwoFactor $twoFactor): void
    {
        if ($twoFactor->required(auth()->user())) {
            $this->addError('code', __('auth.two_factor.cannot_disable'));

            return;
        }

        if (! $this->checkCode($twoFactor)) {
            return;
        }

        $twoFactor->disable(auth()->user());
        $this->reset('recoveryCodes');
        session()->flash('status', __('auth.two_factor.disabled'));
    }

    protected function checkCode(TwoFactor $twoFactor): bool
    {
        $this->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => __('auth.two_factor.code')]);

        if (! $twoFactor->verify(auth()->user(), $this->code)) {
            $this->addError('code', __('auth.two_factor.invalid'));

            return false;
        }

        $this->code = '';

        return true;
    }

    public function render(TwoFactor $twoFactor): View
    {
        $user = auth()->user();
        $secret = session('two_factor.setup_secret');

        return view('livewire.two-factor-settings', [
            'enabled' => $twoFactor->enabled($user),
            'required' => $twoFactor->required($user),
            'secret' => is_string($secret) ? $secret : null,
            'uri' => is_string($secret) ? Totp::uri($secret, (string) $user->email, (string) config('crm.name')) : null,
            'remaining' => $twoFactor->enabled($user) ? $twoFactor->remainingRecoveryCodes($user) : 0,
        ]);
    }
}
