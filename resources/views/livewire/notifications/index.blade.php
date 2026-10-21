<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <label class="flex items-center gap-2 text-sm text-ink"><input wire:model.live="unread" type="checkbox" class="size-4 accent-brand-600"> {{ __('notifications.only_unread') }}</label>
        <div class="flex gap-2">
            <button type="button" wire:click="markAllRead" class="btn btn-secondary btn-sm" data-action="mark-all-read">{{ __('notifications.mark_all_read') }}</button>
            <a href="{{ route('notifications.preferences') }}" wire:navigate class="btn btn-secondary btn-sm">{{ __('notifications.preferences.title') }}</a>
        </div>
    </div>

    <x-flash />

    <ul class="card divide-y divide-line" data-notifications>
        @forelse ($this->notifications as $notification)
            @php
                $data = $notification->data;
                $event = (string) ($data['event'] ?? '');
                $params = (array) ($data['params'] ?? []);
                if (isset($data['kind'])) {
                    $params['what'] = __('notifications.kinds.'.$data['kind']);
                }
            @endphp
            <li @class(['flex flex-wrap items-start justify-between gap-3 p-4', 'bg-brand-50/40' => $notification->read_at === null]) wire:key="n-{{ $notification->id }}" data-notification="{{ $event }}">
                <div class="min-w-0">
                    <p class="text-sm text-ink">
                        @if ($notification->read_at === null) <span class="mr-1 inline-block size-2 rounded-full bg-brand-600" aria-hidden="true"></span><span class="sr-only">{{ __('notifications.unread') }}</span> @endif
                        <span class="font-semibold">{{ trans()->has('notifications.messages.'.$event.'.title') ? __('notifications.messages.'.$event.'.title', $params) : $event }}</span>
                    </p>
                    @if (trans()->has('notifications.messages.'.$event.'.body'))
                        <p class="mt-1 text-sm text-muted">{{ __('notifications.messages.'.$event.'.body', $params) }}</p>
                    @endif
                    <p class="mt-1 text-xs text-muted">{{ $notification->created_at?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) }}</p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <button type="button" wire:click="open('{{ $notification->id }}')" class="btn btn-secondary btn-sm">{{ __('notifications.open') }}</button>
                    @if ($notification->read_at === null)
                        <button type="button" wire:click="markRead('{{ $notification->id }}')" class="btn btn-secondary btn-sm" data-action="mark-read">{{ __('notifications.mark_read') }}</button>
                    @endif
                </div>
            </li>
        @empty
            <li><x-empty-state :title="__('notifications.empty')" icon="clock" /></li>
        @endforelse
    </ul>

    @if ($this->notifications->hasPages())
        <div>{{ $this->notifications->links() }}</div>
    @endif
</div>
