<section class="card p-6 text-sm" aria-labelledby="tg-chat-{{ $chat->id }}" data-telegram-chat="{{ $chat->id }}">
    <x-flash />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 id="tg-chat-{{ $chat->id }}" class="font-semibold break-words text-ink">{{ $chat->displayName() }}</h3>
            <div class="mt-2 flex flex-wrap gap-2">
                <x-badge tone="outline" data-telegram-group-badge>{{ __('telegram.groups.badge') }}</x-badge>
                <x-badge tone="outline">{{ $chat->type->label() }}</x-badge>
                <x-badge :tone="$chat->bot_status === \App\Enums\TelegramBotStatus::Removed ? 'danger-outline' : 'outline'" data-bot-status="{{ $chat->bot_status->value }}">{{ __('telegram.groups.bot') }}: {{ $chat->bot_status->label() }}</x-badge>
            </div>
            <p class="mt-2 text-muted" data-member-count>
                {{ __('telegram.groups.member_count', ['seen' => $activeCount, 'total' => $chat->member_count ?? '—']) }}
                @if ($chat->members_refreshed_at) · {{ __('telegram.groups.refreshed_at', ['at' => $chat->members_refreshed_at->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat())]) }}@endif
            </p>
            @if ($chat->link())
                <a href="{{ $chat->link() }}" class="link text-xs" rel="noopener noreferrer" target="_blank">{{ $chat->link() }}</a>
            @endif
        </div>
        @if ($canManage)
            <button type="button" wire:click="refreshMembers" wire:loading.attr="disabled" class="btn btn-secondary btn-sm" data-action="refresh-members">{{ __('telegram.groups.refresh') }}</button>
        @endif
    </div>
    @error('telegram') <p class="form-error mt-2" role="alert">{{ $message }}</p> @enderror

    <div class="mt-4 border-t border-line pt-4">
        <p class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('telegram.groups.company') }}</p>
        @if ($chat->customer)
            <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                @can('view', $chat->customer)
                    <a href="{{ route('customers.show', $chat->customer) }}" wire:navigate class="link font-medium">{{ $chat->customer->name }}</a>
                @else
                    <span class="font-medium text-ink">{{ $chat->customer->name }}</span>
                @endcan
                @if ($canManage)
                    <button type="button" wire:click="unlinkCustomer" wire:confirm="{{ __('telegram.groups.confirm_unlink') }}" class="btn btn-secondary btn-sm" data-action="unlink-customer">{{ __('telegram.groups.unlink') }}</button>
                @endif
            </div>
        @else
            <p class="mt-1 text-muted">{{ __('telegram.groups.no_company') }}</p>
            <div class="mt-2 flex flex-wrap gap-2">
                @if ($canCreateCustomer)
                    <a href="{{ route('customers.create', ['lead' => $chat->lead_id]) }}" wire:navigate class="btn btn-primary btn-sm" data-action="create-company-from-group">{{ __('telegram.groups.create_company') }}</a>
                @endif
            </div>
            @if ($canManage)
                <form wire:submit="linkCustomer" class="mt-3 space-y-2" data-form="link-customer" novalidate>
                    <label for="tg-search-{{ $chat->id }}" class="form-label">{{ __('telegram.groups.link_existing') }}</label>
                    <input wire:model.live.debounce.300ms="customerSearch" id="tg-search-{{ $chat->id }}" type="search" class="form-input" placeholder="{{ __('telegram.groups.search_customer') }}" autocomplete="off">
                    @if ($this->customerOptions->isNotEmpty())
                        <label for="tg-customer-{{ $chat->id }}" class="sr-only">{{ __('telegram.groups.company') }}</label>
                        <select wire:model="linkCustomerId" id="tg-customer-{{ $chat->id }}" class="form-input">
                            <option value="">—</option>
                            @foreach ($this->customerOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}@if ($option->tax_id) · {{ $option->tax_id }}@endif</option>
                            @endforeach
                        </select>
                    @endif
                    @error('linkCustomerId') <p class="form-error">{{ $message }}</p> @enderror
                    <button type="submit" class="btn btn-secondary btn-sm" data-action="link-customer">{{ __('telegram.groups.link') }}</button>
                </form>
            @endif
        @endif
    </div>

    <form wire:submit="createContacts" class="mt-4 border-t border-line pt-4" data-form="create-contacts" novalidate>
        <fieldset>
            <legend class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('telegram.groups.members') }}</legend>
            <p class="mt-1 text-xs text-muted">{{ __('telegram.groups.members_hint') }}</p>
            <ul class="mt-2 divide-y divide-line" data-members>
                @forelse ($this->members as $member)
                    @php($person = $member->telegramContact)
                    @php($reason = $skipReason($member))
                    <li class="flex items-start gap-3 py-2" wire:key="tgm-{{ $member->id }}" data-member="{{ $person?->telegram_user_id }}">
                        @if ($canCreateContacts && $reason === null && $member->role !== \App\Enums\TelegramMemberRole::Left && $person)
                            <input wire:model="selected" value="{{ $person->id }}" id="tgm-{{ $member->id }}" type="checkbox" class="mt-1 rounded border-line">
                        @else
                            <span class="mt-1 inline-block size-4" aria-hidden="true"></span>
                        @endif
                        <label for="tgm-{{ $member->id }}" class="min-w-0 flex-1">
                            <span class="font-medium text-ink">{{ $person?->fullName() }}</span>
                            @if ($person?->username)<span class="text-muted"> · {{ '@'.$person->username }}</span>@endif
                            <span class="block text-xs text-muted">
                                {{ $member->role->label() }} · {{ __('telegram.groups.sources.'.$member->source) }} · {{ $member->last_seen_at->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}
                                @if ($person?->contact) · {{ __('telegram.groups.contact_of', ['name' => $person->contact->customer?->name]) }}@endif
                                @if ($person?->user?->isInternal()) · {{ __('telegram.groups.staff') }}@elseif ($person?->user) · {{ __('telegram.groups.portal_user') }}@endif
                            </span>
                            @if (isset($conflicts[$member->id]))
                                <span class="mt-1 block text-xs text-brand-700" data-member-conflict>{{ __('telegram.groups.conflict', ['companies' => $conflicts[$member->id]]) }}</span>
                            @endif
                            @if ($reason && $reason !== 'exists')
                                <span class="block text-xs text-muted">{{ __('telegram.groups.skip.'.$reason) }}</span>
                            @endif
                        </label>
                    </li>
                @empty
                    <li class="py-2 text-muted">{{ __('telegram.groups.no_members') }}</li>
                @endforelse
            </ul>
        </fieldset>
        @error('selected') <p class="form-error">{{ $message }}</p> @enderror

        @if ($canCreateContacts && $this->members->isNotEmpty())
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                @if ($canInvite)
                    <label class="flex items-center gap-2 text-ink"><input wire:model="invite" type="checkbox" class="rounded border-line" data-field="invite"> {{ __('telegram.groups.invite') }}</label>
                @endif
                <button type="submit" class="btn btn-primary btn-sm" data-action="create-contacts">{{ __('telegram.groups.create_contacts') }}</button>
            </div>
            @if ($canInvite)<p class="mt-1 text-xs text-muted">{{ __('telegram.groups.invite_hint') }}</p>@endif
        @endif
    </form>
</section>
