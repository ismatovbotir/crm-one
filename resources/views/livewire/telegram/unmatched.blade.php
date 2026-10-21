<section class="card mt-6" aria-labelledby="tg-unmatched" data-telegram-unmatched>
    <div class="border-b border-line px-6 py-4">
        <h2 id="tg-unmatched" class="font-semibold text-ink">{{ $userId ? __('telegram.matching.user_section') : __('telegram.matching.title') }}</h2>
        <p class="mt-1 text-sm text-muted">{{ __('telegram.matching.intro') }}</p>
    </div>

    @if ($userId && $link)
        <div class="border-b border-line px-6 py-4 text-sm" data-link-status="{{ $link->isPending() ? 'pending' : 'verified' }}">
            <p class="text-ink">
                {{ $link->username ? '@'.$link->username : ($link->first_name ?? $link->telegram_user_id) }}
                <x-badge tone="outline">{{ $link->isPending() ? __('telegram.matching.pending') : __('telegram.matching.verified') }}</x-badge>
            </p>
            @if ($link->isPending())
                <p class="mt-1 text-xs text-muted">{{ __('telegram.matching.pending_hint') }}</p>
                @if ($canForce)
                    <form wire:submit="forceVerify" class="mt-3 flex flex-wrap items-end gap-2" novalidate data-force-verify>
                        <div class="min-w-64 flex-1">
                            <label for="tg-force-reason" class="form-label">{{ __('telegram.matching.force_reason') }}</label>
                            <input wire:model="forceReason" id="tg-force-reason" type="text" maxlength="500" class="form-input">
                            @error('forceReason') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm">{{ __('telegram.matching.force') }}</button>
                    </form>
                @endif
            @endif
            <button type="button" wire:click="unlink" wire:confirm="{{ __('telegram.cabinet.confirm_unlink') }}" class="btn btn-secondary btn-sm mt-3">{{ __('users.telegram_unlink') }}</button>
        </div>
    @endif

    @if (! $userId || ! $link)
        <div class="flex flex-wrap items-center gap-4 border-b border-line px-6 py-3" role="search">
            <div class="min-w-64 flex-1">
                <label for="tg-search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="tg-search" type="search" class="form-input" placeholder="{{ __('telegram.matching.search') }}">
            </div>
            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model.live="includeCustomerGroups" type="checkbox" class="rounded border-line"> {{ __('telegram.matching.include_customer_groups') }}</label>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs text-muted uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-2">{{ __('common.name') }}</th>
                        <th scope="col" class="px-4 py-2">{{ __('telegram.matching.language') }}</th>
                        <th scope="col" class="px-4 py-2">{{ __('telegram.matching.seen') }}</th>
                        <th scope="col" class="px-4 py-2">{{ __('telegram.matching.chats') }}</th>
                        <th scope="col" class="px-4 py-2"><span class="sr-only">{{ __('common.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($this->contacts as $contact)
                        <tr wire:key="tgc-{{ $contact->id }}" data-unmatched="{{ $contact->telegram_user_id }}">
                            <td class="px-4 py-2 text-ink">{{ $contact->fullName() }} @if ($contact->username)<span class="text-muted">{{ '@'.$contact->username }}</span>@endif</td>
                            <td class="px-4 py-2 text-muted">{{ $contact->language_code ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-muted">{{ $contact->created_at?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }} – {{ $contact->updated_at?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}</td>
                            <td class="px-4 py-2 text-xs text-muted">
                                {{ $contact->memberships->map(fn ($m) => $m->chat ? ($m->chat->type->value === 'private' ? __('telegram.matching.private') : $m->chat->displayName().($m->chat->isInternal() ? ' ('.__('telegram.internal.purpose.internal_notifications').')' : '')) : null)->filter()->unique()->join(', ') ?: '—' }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @unless ($userId)
                                        <label for="tgu-{{ $contact->id }}" class="sr-only">{{ __('telegram.matching.choose_user') }}</label>
                                        <select wire:model="chosen.{{ $contact->id }}" id="tgu-{{ $contact->id }}" class="form-input w-48">
                                            <option value="">{{ __('telegram.matching.choose_user') }}</option>
                                            @foreach ($this->staff as $staffUser)
                                                <option value="{{ $staffUser->id }}">{{ $staffUser->name }}</option>
                                            @endforeach
                                        </select>
                                    @endunless
                                    <button type="button" wire:click="link({{ $contact->id }})" class="btn btn-secondary btn-sm" data-action="match-telegram">{{ $userId ? __('telegram.matching.link') : __('telegram.matching.match') }}</button>
                                </div>
                                @error('contact-'.$contact->id) <p class="form-error text-left" role="alert">{{ $message }}</p> @enderror
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-4 text-sm text-muted">{{ __('telegram.matching.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-line p-4">{{ $this->contacts->links() }}</div>
    @endif
</section>
