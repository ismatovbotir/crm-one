<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('agreements.intro') }}</p>
    </div>

    <x-flash />

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5" data-queues>
        @foreach (\App\Livewire\Agreements\Index::QUEUES as $queueKey)
            <button type="button" wire:click="$set('queue', '{{ $queue === $queueKey ? '' : $queueKey }}')" wire:key="queue-{{ $queueKey }}"
                    @class(['card p-4 text-left transition-colors', 'border-2 border-brand-600' => $queue === $queueKey, 'hover:bg-brand-50/40' => $queue !== $queueKey])>
                <span class="block text-2xl font-semibold text-ink">{{ $this->queueCounts[$queueKey] }}</span>
                <span class="text-sm text-muted">{{ __('agreements.queues.'.$queueKey) }}</span>
            </button>
        @endforeach
    </div>

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto] lg:items-end" role="search">
            <div>
                <label for="search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" placeholder="{{ __('agreements.search_placeholder') }}" class="form-input">
            </div>
            <div>
                <label for="status-filter" class="sr-only">{{ __('common.status') }}</label>
                <select wire:model.live="status" id="status-filter" class="form-input">
                    <option value="">{{ __('common.all_statuses') }}</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="kind-filter" class="sr-only">{{ __('agreements.fields.kind') }}</label>
                <select wire:model.live="kind" id="kind-filter" class="form-input">
                    <option value="">{{ __('agreements.any_kind') }}</option>
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" wire:click="resetFilters" class="btn btn-secondary">{{ __('common.reset_filters') }}</button>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('agreements.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('agreements.fields.customer') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('agreements.fields.order') }}</th>
                        <th scope="col" class="hidden px-4 py-3 lg:table-cell">{{ __('agreements.fields.term') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->agreements as $agreement)
                        <tr wire:key="agreement-{{ $agreement->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="{{ route('agreements.show', $agreement) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $agreement->number }}</a>
                                <span class="block text-xs text-muted">{{ $agreement->kind->label() }}</span>
                            </td>
                            <td class="max-w-xs px-4 py-3"><span class="block truncate text-ink">{{ $agreement->customer?->name }}</span></td>
                            <td class="px-4 py-3">
                                <x-agreements.status-badge :status="$agreement->status" />
                                @if ($agreement->status === \App\Enums\AgreementStatus::SentForSignature && $agreement->sent_at)
                                    <span class="block text-xs text-muted">{{ trans_choice('agreements.days_waiting', (int) $agreement->sent_at->diffInDays(now()), ['count' => (int) $agreement->sent_at->diffInDays(now())]) }}</span>
                                @endif
                                @if ($agreement->customer_signed_uploaded_at && $agreement->status === \App\Enums\AgreementStatus::SentForSignature)
                                    <span class="block text-xs font-semibold text-brand-700">{{ __('agreements.scan_uploaded') }}</span>
                                @endif
                            </td>
                            <td class="hidden px-4 py-3 font-mono whitespace-nowrap text-ink md:table-cell">{{ $agreement->order?->number ?? '—' }}</td>
                            <td class="hidden px-4 py-3 whitespace-nowrap text-ink lg:table-cell">{{ $agreement->starts_on?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }} – {{ $agreement->ends_on?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-0">
                                <x-empty-state :title="__('agreements.empty')" :description="__('agreements.empty_hint')" icon="agreements" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->agreements->hasPages())
            <div class="border-t border-line p-4">{{ $this->agreements->links() }}</div>
        @endif
    </div>
</div>
