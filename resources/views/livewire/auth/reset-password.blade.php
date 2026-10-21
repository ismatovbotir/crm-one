<div>
    <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('auth.reset_title') }}</h1>
    <p class="mt-1 text-sm text-muted">{{ __('auth.reset_intro') }}</p>

    <form wire:submit="resetPassword" class="mt-8 space-y-5" novalidate>
        <div>
            <label for="email" class="form-label">{{ __('common.email') }}</label>
            <input wire:model="email" id="email" type="email" autocomplete="email" required class="form-input">
            @error('email') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="form-label">{{ __('common.new_password') }}</label>
            <input wire:model="password" id="password" type="password" autocomplete="new-password" required class="form-input">
            @error('password') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="form-label">{{ __('common.confirm_password') }}</label>
            <input wire:model="password_confirmation" id="password_confirmation" type="password" autocomplete="new-password" required class="form-input">
        </div>

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">{{ __('auth.reset_submit') }}</button>
    </form>
</div>
