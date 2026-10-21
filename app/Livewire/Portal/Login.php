<?php

namespace App\Livewire\Portal;

use App\Services\Auth\PortalLogin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Portal sign-in (Spec 03 4.2): identifier (e-mail, Telegram @username or phone) + channel, then
 * the 6-digit code. Always the same generic answer after "Send code" (AC-2).
 */
#[Layout('layouts.guest')]
class Login extends Component
{
    public string $identifier = '';

    public string $channel = 'email';

    public string $code = '';

    public bool $remember = false;

    public bool $sent = false;

    public function send(PortalLogin $login): void
    {
        $this->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:email,telegram'],
        ], [], ['identifier' => __('portal.login.identifier')]);

        $cooldown = $login->requestCode($this->identifier, $this->channel);
        $this->sent = true;
        session()->flash('status', $cooldown ? __('portal.login.wait') : __('portal.login.sent_generic'));
    }

    public function verify(PortalLogin $login): void
    {
        $this->validate(['code' => ['required', 'digits:6']], [], ['code' => __('portal.login.code')]);

        if ($login->verify($this->identifier, $this->code, $this->remember) === null) {
            $this->addError('code', __('portal.login.invalid'));
            $this->code = '';

            return;
        }

        $this->redirectRoute('portal.dashboard', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.portal.login')->title(__('portal.login.title'));
    }
}
