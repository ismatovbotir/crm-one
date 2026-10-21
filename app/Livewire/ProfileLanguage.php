<?php

namespace App\Livewire;

use App\Enums\Locale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * "Default language" in My cabinet (Spec 03 section 17.10): the user's own users.locale, used at
 * sign-in when the session has no explicit choice, and for notifications, e-mails and Telegram.
 */
class ProfileLanguage extends Component
{
    public string $locale = 'uz';

    public function mount(): void
    {
        $this->locale = (Auth::user()?->locale ?? Locale::default())->value;
    }

    public function save(): void
    {
        $user = Auth::user();
        abort_if($user === null, 403);
        $this->validate(['locale' => ['required', Rule::enum(Locale::class)]], [], ['locale' => __('users.locale')]);
        $user->forceFill(['locale' => Locale::from($this->locale)])->save();
        session()->put(Locale::SESSION_KEY, $this->locale);
        session()->flash('status', __('users.locale_saved'));
        $this->redirect(url()->previous(), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.profile-language', ['locales' => Locale::cases()]);
    }
}
