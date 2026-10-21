<div class="space-y-6" data-cabinet>
    <x-flash />

    <section class="card p-6">
        <h2 class="font-semibold text-ink">{{ $user->name }}</h2>
        <p class="text-sm text-muted">{{ $user->email }}</p>
        @isset($membership)
            <p class="mt-2 text-sm text-ink">{{ $membership->customer?->name }} · {{ collect($membership->roles)->map(fn ($role) => \App\Enums\CustomerPortalRole::tryFrom($role)?->label())->filter()->implode(', ') }}</p>
        @endisset
    </section>

    <section class="card space-y-3 p-6" aria-labelledby="tg-title" data-telegram>
        <h2 id="tg-title" class="font-semibold text-ink">{{ __('telegram.cabinet.section') }}</h2>
        @if ($link)
            <p class="text-sm text-ink" data-telegram-status="linked">{{ __('telegram.cabinet.linked', ['username' => $link->username ? '@'.$link->username : ($link->first_name ?? '—'), 'since' => $link->linked_at->translatedFormat(\App\Enums\Locale::current()->dateFormat())]) }}</p>
            @if ($link->isPending())
                {{-- Spec 03 section 17.10: an administrator linked this chat; prove it is yours. --}}
                <div class="rounded-md border border-brand-600 bg-brand-50 px-3 py-3 text-sm text-brand-800" data-telegram-pending>
                    <p class="font-semibold">{{ __('telegram.matching.confirm_title', ['username' => $link->username ? '@'.$link->username : ($link->first_name ?? '—')]) }}</p>
                    <p class="mt-1">{{ __('telegram.matching.confirm_hint') }}</p>
                    <div class="mt-3 flex flex-wrap items-end gap-2">
                        <button type="button" wire:click="sendVerificationCode" class="btn btn-secondary btn-sm" data-action="send-telegram-code">{{ __('telegram.matching.send_code') }}</button>
                        <form wire:submit="confirmTelegram" class="flex items-end gap-2" novalidate>
                            <div>
                                <label for="tg-code" class="form-label">{{ __('telegram.matching.code') }}</label>
                                <input wire:model="verificationCode" id="tg-code" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="form-input w-32 font-mono">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm" data-action="confirm-telegram">{{ __('telegram.matching.confirm') }}</button>
                        </form>
                    </div>
                    @error('verificationCode') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
            @endif
            @if ($link->blocked_at)
                <p class="rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-sm text-brand-800" data-telegram-blocked>{{ __('telegram.cabinet.blocked') }}</p>
            @endif
            <button type="button" wire:click="unlinkTelegram" wire:confirm="{{ __('telegram.cabinet.confirm_unlink') }}" class="btn btn-secondary">{{ __('telegram.cabinet.unlink') }}</button>
        @else
            <p class="text-sm text-muted" data-telegram-status="none">{{ __('telegram.cabinet.not_linked') }}</p>
            @if ($botEnabled)
                <button type="button" wire:click="createTelegramLink" class="btn btn-primary" data-action="link-telegram">{{ __('telegram.cabinet.link') }}</button>
            @else
                <p class="text-xs text-muted">{{ __('telegram.cabinet.bot_off') }}</p>
            @endif
        @endif
        @if ($deepLink !== '')
            <p class="text-sm text-ink">{{ __('telegram.cabinet.open_hint') }}</p>
            <a href="{{ $deepLink }}" target="_blank" rel="noopener noreferrer" class="link break-all font-mono text-sm" data-deep-link>{{ $deepLink }}</a>
            <p class="text-xs text-muted">{{ __('telegram.cabinet.security_hint') }}</p>
        @endif
    </section>

    <livewire:profile-language />

    @unless (isset($membership))
        <livewire:two-factor-settings />
    @endunless
</div>
