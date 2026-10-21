<section class="card space-y-4 p-6" aria-labelledby="tf-title" data-two-factor>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="tf-title" class="font-semibold text-ink">{{ __('auth.two_factor.section') }}</h2>
        <x-badge :tone="$enabled ? 'dark' : 'outline'" :icon="$enabled ? 'check' : 'lock'" data-two-factor-state="{{ $enabled ? 'on' : 'off' }}">{{ $enabled ? __('auth.two_factor.on') : __('auth.two_factor.off') }}</x-badge>
    </div>
    @if ($required && ! $enabled)
        <p class="rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-sm text-brand-800" role="alert">{{ __('auth.two_factor.required') }}</p>
    @endif

    @if ($recoveryCodes !== [])
        <div class="rounded-md border border-line-strong bg-neutral-50 p-4" data-recovery-codes>
            <p class="text-sm font-semibold text-ink">{{ __('auth.two_factor.codes_title') }}</p>
            <p class="mt-1 text-xs text-muted">{{ __('auth.two_factor.codes_hint') }}</p>
            <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-ink">
                @foreach ($recoveryCodes as $recovery)
                    <li>{{ $recovery }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $enabled)
        @if ($secret)
            <ol class="list-inside list-decimal space-y-2 text-sm text-ink">
                <li>{{ __('auth.two_factor.step_app') }}</li>
                <li>{{ __('auth.two_factor.step_secret') }} <span class="block font-mono text-base tracking-widest break-all" data-two-factor-secret>{{ trim(chunk_split($secret, 4, ' ')) }}</span>
                    <a href="{{ $uri }}" class="link text-xs">{{ __('auth.two_factor.open_app') }}</a></li>
                <li>{{ __('auth.two_factor.step_code') }}</li>
            </ol>
            <form wire:submit="confirm" class="flex flex-wrap items-end gap-3" novalidate>
                <div>
                    <label for="tf-confirm" class="form-label">{{ __('auth.two_factor.code') }}</label>
                    <input wire:model="code" id="tf-confirm" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="form-input w-40 text-center font-mono tracking-widest">
                    @error('code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary" data-action="confirm-2fa">{{ __('auth.two_factor.confirm') }}</button>
            </form>
        @else
            <p class="text-sm text-muted">{{ __('auth.two_factor.explain') }}</p>
            <button type="button" wire:click="start" class="btn btn-primary" data-action="start-2fa">{{ __('auth.two_factor.enable') }}</button>
        @endif
    @else
        <p class="text-sm text-muted">{{ __('auth.two_factor.remaining', ['count' => $remaining]) }}</p>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="tf-current" class="form-label">{{ __('auth.two_factor.current_code') }}</label>
                <input wire:model="code" id="tf-current" type="text" autocomplete="one-time-code" maxlength="20" class="form-input w-48 text-center font-mono tracking-widest">
                @error('code') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <button type="button" wire:click="regenerate" class="btn btn-secondary" data-action="regenerate-codes">{{ __('auth.two_factor.regenerate') }}</button>
            @unless ($required)
                <button type="button" wire:click="disable" wire:confirm="{{ __('auth.two_factor.confirm_disable') }}" class="btn btn-danger-outline" data-action="disable-2fa">{{ __('auth.two_factor.disable') }}</button>
            @endunless
        </div>
    @endif
</section>
