<div class="space-y-6" data-two-factor-challenge>
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('auth.two_factor.title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('auth.two_factor.intro') }}</p>
    </div>

    <form wire:submit="verify" class="space-y-4" novalidate>
        <div>
            <label for="tf-code" class="form-label">{{ __('auth.two_factor.code') }}</label>
            <input wire:model="code" id="tf-code" type="text" inputmode="text" autocomplete="one-time-code" maxlength="20" class="form-input text-center font-mono text-lg tracking-widest" autofocus>
            <p class="mt-1 text-xs text-muted">{{ __('auth.two_factor.code_hint') }}</p>
            @error('code') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" data-action="verify-2fa">{{ __('auth.two_factor.verify') }}</button>
    </form>

    <a href="{{ route('login') }}" class="link text-sm">{{ __('auth.two_factor.back') }}</a>
</div>
