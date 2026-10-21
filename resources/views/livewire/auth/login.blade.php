<div>
    <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('auth.sign_in') }}</h1>
    <p class="mt-1 text-sm text-muted">{{ __('auth.sign_in_intro') }}</p>

    <x-flash class="mt-6" />

    <form wire:submit="login" class="mt-8 space-y-5" novalidate>
        <div>
            <label for="email" class="form-label">{{ __('common.email') }}</label>
            <input wire:model="email" id="email" type="email" autocomplete="username" required autofocus class="form-input" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email') <p id="email-error" class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <div class="mb-1 flex items-center justify-between">
                <label for="password" class="text-sm font-medium text-ink">{{ __('common.password') }}</label>
                <a href="{{ route('password.request') }}" class="link text-sm">{{ __('auth.forgot_password_link') }}</a>
            </div>
            <input wire:model="password" id="password" type="password" autocomplete="current-password" required class="form-input" @error('password') aria-invalid="true" @enderror>
            @error('password') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-ink">
            <input wire:model="remember" type="checkbox" class="size-4 rounded border-line-strong accent-brand-600">
            {{ __('auth.remember_me') }}
        </label>

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">{{ __('auth.sign_in') }}</span>
            <span wire:loading wire:target="login">{{ __('auth.signing_in') }}</span>
        </button>
    </form>
</div>
