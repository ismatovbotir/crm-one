<?php

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
class ForgotPassword extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    public function sendPasswordResetLink(): void
    {
        $this->validate();

        Password::sendResetLink(['email' => $this->email]);

        // Same message whether or not the account exists, to avoid account enumeration.
        session()->flash('status', __('auth.reset_link_sent'));

        $this->reset('email');
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password')->title(__('auth.forgot_title'));
    }
}
