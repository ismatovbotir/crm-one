<div class="space-y-6" data-portal-login>
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ __('portal.login.title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('portal.login.intro') }}</p>
    </div>

    <x-flash />

    <form wire:submit="send" class="space-y-4" novalidate>
        <div>
            <label for="pl-identifier" class="form-label">{{ __('portal.login.identifier') }}</label>
            <input wire:model="identifier" id="pl-identifier" type="text" autocomplete="username" class="form-input" placeholder="{{ __('portal.login.identifier_placeholder') }}">
            @error('identifier') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <fieldset>
            <legend class="form-label">{{ __('portal.login.channel') }}</legend>
            <div class="flex gap-4">
                <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="channel" type="radio" value="email" class="accent-brand-600"> {{ __('portal.login.channels.email') }}</label>
                <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="channel" type="radio" value="telegram" class="accent-brand-600"> {{ __('portal.login.channels.telegram') }}</label>
            </div>
        </fieldset>
        <button type="submit" class="btn btn-secondary w-full" wire:loading.attr="disabled" data-action="send-code">{{ __('portal.login.send') }}</button>
    </form>

    @if ($sent)
        <form wire:submit="verify" class="space-y-4 border-t border-line pt-4" novalidate>
            <div>
                <label for="pl-code" class="form-label">{{ __('portal.login.code') }}</label>
                <input wire:model="code" id="pl-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="form-input text-center font-mono text-lg tracking-widest">
                @error('code') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="remember" type="checkbox" class="rounded border-line"> {{ __('portal.login.remember') }}</label>
            <button type="submit" class="btn btn-primary w-full" data-action="verify">{{ __('portal.login.verify') }}</button>
        </form>
    @endif

    <p class="text-xs text-muted">{{ __('portal.login.never_share') }}</p>
</div>
