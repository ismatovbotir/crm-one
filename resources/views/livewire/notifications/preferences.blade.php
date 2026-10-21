<div class="space-y-6">
    <a href="{{ route('notifications.index') }}" wire:navigate class="link text-sm">&larr; {{ __('notifications.back') }}</a>

    <x-flash />

    <form wire:submit="save" class="card" novalidate>
        <div class="border-b border-line p-5">
            <p class="text-sm text-muted">{{ __('notifications.preferences.intro') }}</p>
            @unless ($telegramLinked)
                <p class="mt-2 flex items-center gap-1 text-xs text-muted"><x-icon name="lock" class="size-3.5" /> {{ __('notifications.preferences.telegram_not_linked') }} <a href="{{ route('cabinet') }}" wire:navigate class="link">{{ __('telegram.cabinet.title') }}</a></p>
            @endunless
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm" data-preferences>
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('notifications.preferences.event') }}</th>
                        <th scope="col" class="px-4 py-3 text-center">{{ __('notifications.channels.database') }}</th>
                        <th scope="col" class="px-4 py-3 text-center">{{ __('notifications.channels.mail') }}</th>
                        <th scope="col" class="px-4 py-3 text-center">{{ __('notifications.channels.telegram') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @foreach ($events as $event)
                        <tr wire:key="pref-{{ $event->value }}">
                            <th scope="row" class="px-4 py-3 text-left font-normal text-ink">{{ $event->label() }}</th>
                            <td class="px-4 py-3 text-center"><input type="checkbox" checked disabled class="size-4 accent-brand-600" aria-label="{{ $event->label() }}: {{ __('notifications.channels.database') }} ({{ __('notifications.preferences.always') }})"></td>
                            <td class="px-4 py-3 text-center"><input wire:model="choices.{{ $event->value }}.mail" type="checkbox" class="size-4 accent-brand-600" aria-label="{{ $event->label() }}: {{ __('notifications.channels.mail') }}"></td>
                            <td class="px-4 py-3 text-center"><input wire:model="choices.{{ $event->value }}.telegram" type="checkbox" class="size-4 accent-brand-600" aria-label="{{ $event->label() }}: {{ __('notifications.channels.telegram') }}" @disabled(! $telegramLinked)></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="flex justify-end border-t border-line p-4"><button type="submit" class="btn btn-primary">{{ __('common.save') }}</button></div>
    </form>
</div>
