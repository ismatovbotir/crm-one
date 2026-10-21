<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('telegram.chats.intro') }}</p>
    <p class="text-sm text-muted" data-privacy-note>{{ __('telegram.chats.privacy_note') }}</p>

    <x-flash />

    <div class="card flex flex-wrap items-end gap-4 p-4">
        <div class="min-w-48 flex-1">
            <label for="tg-chats-search" class="form-label">{{ __('common.search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="tg-chats-search" type="search" class="form-input">
        </div>
        <div>
            <label for="tg-chats-filter" class="form-label">{{ __('telegram.chats.filter') }}</label>
            <select wire:model.live="filter" id="tg-chats-filter" class="form-input">
                <option value="">{{ __('telegram.chats.filters.all') }}</option>
                <option value="unlinked">{{ __('telegram.chats.filters.unlinked') }}</option>
                <option value="removed">{{ __('telegram.chats.filters.removed') }}</option>
            </select>
        </div>
    </div>

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm" data-telegram-chats>
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('telegram.chats.columns.chat') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('telegram.chats.columns.type') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('telegram.chats.columns.bot') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('telegram.chats.columns.company') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('telegram.chats.columns.lead') }}</th>
                    <th scope="col" class="px-4 py-3 text-right">{{ __('telegram.chats.columns.members') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('telegram.chats.columns.last_message') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($this->chats as $chat)
                    <tr wire:key="tgc-{{ $chat->id }}" data-chat="{{ $chat->chat_id }}">
                        <td class="px-4 py-3 text-ink">
                            {{ $chat->displayName() }}
                            @if ($chat->mergedInto) <span class="block text-xs text-muted">{{ __('telegram.chats.merged_into', ['name' => $chat->mergedInto->displayName()]) }}</span>@endif
                        </td>
                        <td class="px-4 py-3">{{ $chat->type->label() }}@if ($chat->isInternal()) <x-badge tone="outline" data-internal-chat>{{ $chat->purpose->label() }}</x-badge>@endif</td>
                        <td class="px-4 py-3"><x-badge :tone="$chat->bot_status === \App\Enums\TelegramBotStatus::Removed ? 'danger-outline' : 'outline'">{{ $chat->bot_status->label() }}</x-badge></td>
                        <td class="px-4 py-3">
                            @if ($chat->customer)
                                @can('view', $chat->customer)<a href="{{ route('customers.show', ['customer' => $chat->customer, 'tab' => 'telegram']) }}" wire:navigate class="link">{{ $chat->customer->name }}</a>@else{{ $chat->customer->name }}@endcan
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($chat->lead)
                                @can('view', $chat->lead)<a href="{{ route('leads.show', $chat->lead) }}" wire:navigate class="link font-mono text-xs">{{ $chat->lead->number }}</a>@else<span class="font-mono text-xs">{{ $chat->lead->number }}</span>@endcan
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $chat->member_count ?? '—' }}</td>
                        <td class="px-4 py-3 text-muted">{{ $chat->last_message_at?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-0"><x-empty-state :title="__('telegram.chats.empty')" icon="contact" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($mergeable->count() > 1)
        <form wire:submit="merge" class="card grid gap-4 p-5 sm:grid-cols-3" data-form="merge-chats" novalidate>
            <h2 class="font-semibold text-ink sm:col-span-3">{{ __('telegram.chats.merge_title') }}</h2>
            <p class="text-xs text-muted sm:col-span-3">{{ __('telegram.chats.merge_hint') }}</p>
            <div>
                <label for="tg-merge-from" class="form-label">{{ __('telegram.chats.merge_from') }}</label>
                <select wire:model="mergeId" id="tg-merge-from" class="form-input">
                    <option value="">—</option>
                    @foreach ($mergeable as $chat)<option value="{{ $chat->id }}">{{ $chat->displayName() }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="tg-merge-into" class="form-label">{{ __('telegram.chats.merge_into') }}</label>
                <select wire:model="mergeIntoId" id="tg-merge-into" class="form-input">
                    <option value="">—</option>
                    @foreach ($mergeable as $chat)<option value="{{ $chat->id }}">{{ $chat->displayName() }}</option>@endforeach
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" wire:confirm="{{ __('telegram.chats.confirm_merge') }}" class="btn btn-secondary" data-action="merge-chats">{{ __('telegram.chats.merge') }}</button>
            </div>
            @error('merge') <p class="form-error sm:col-span-3" role="alert">{{ $message }}</p> @enderror
        </form>
    @endif
</div>
