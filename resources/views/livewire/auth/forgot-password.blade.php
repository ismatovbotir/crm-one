<div>
    <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('auth.forgot_title') }}</h1>
    <p class="mt-1 text-sm text-muted">{{ __('auth.forgot_intro') }}</p>

    <x-flash class="mt-6" />

    <form wire:submit="sendPasswordResetLink" class="mt-8 space-y-5" novalidate>
        <div>
            <label for="email" class="form-label">{{ __('common.email') }}</label>
            <input wire:model="email" id="email" type="email" autocomplete="email" required autofocus class="form-input">
            @error('email') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">{{ __('auth.send_reset_link') }}</button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        <a href="{{ route('login') }}" class="link">{{ __('auth.back_to_sign_in') }}</a>
    </p>
</div>
