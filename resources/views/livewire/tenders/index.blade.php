<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('tenders.intro.'.$type) }}</p>
        @can('create', [\App\Models\Tender::class, $type])
            <button type="button" wire:click="$toggle('creating')" class="btn btn-primary shrink-0" data-action="new-tender"><x-icon name="plus" class="size-4" /> {{ __('tenders.new.'.$type) }}</button>
        @endcan
    </div>

    <x-flash />

    @if ($creating)
        <form wire:submit="create" class="card grid gap-4 p-5 sm:grid-cols-[2fr_1fr_1fr]" novalidate data-new-tender>
            <div>
                <label for="t-title" class="form-label">{{ __('tenders.fields.title') }}</label>
                <input wire:model="form.title" id="t-title" type="text" class="form-input" required>
                @error('form.title') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="t-method" class="form-label">{{ __('tenders.fields.method') }}</label>
                <select wire:model="form.method" id="t-method" class="form-input">
                    @foreach ($methods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="t-kind" class="form-label">{{ __('tenders.fields.kind') }}</label>
                <select wire:model="form.kind" id="t-kind" class="form-input">
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex justify-end gap-2 sm:col-span-3">
                <button type="button" wire:click="$set('creating', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('tenders.create') }}</button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-[2fr_1fr_auto]" role="search">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('tenders.search_placeholder') }}" class="form-input" aria-label="{{ __('common.search') }}">
            <select wire:model.live="status" class="form-input" aria-label="{{ __('common.status') }}">
                <option value="">{{ __('common.all_statuses') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model.live="mine" type="checkbox" class="rounded border-line"> {{ __('tenders.only_mine') }}</label>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('tenders.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('tenders.fields.owner') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('tenders.fields.bids_due_at') }} ({{ \App\Services\Tenders\TenderClock::label() }})</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('tenders.fields.bids') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('tenders.fields.lots') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->tenders as $tender)
                        <tr wire:key="tender-{{ $tender->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3"><a href="{{ route('tenders.show', $tender) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $tender->number }}</a><span class="block text-ink">{{ $tender->title }}</span></td>
                            <td class="px-4 py-3">@php([$tone, $icon] = $tender->status->badge())<x-badge :tone="$tone" :icon="$icon">{{ $tender->status->label() }}</x-badge></td>
                            <td class="px-4 py-3 text-ink">{{ $tender->owner?->name ?? __('tenders.unassigned') }}@if ($tender->owner_id === null && $tender->requester)<span class="block text-xs text-muted">{{ __('tenders.requested_by', ['name' => $tender->requester->name]) }}</span>@endif</td>
                            <td class="px-4 py-3 whitespace-nowrap text-ink">{{ $tender->localTime($tender->bids_due_at)?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-ink">{{ $tender->bids_count }}</td>
                            <td class="px-4 py-3 text-right text-ink">{{ $tender->lots_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-0"><x-empty-state :title="__('tenders.empty')" icon="offers" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->tenders->hasPages())
            <div class="border-t border-line p-4">{{ $this->tenders->links() }}</div>
        @endif
    </div>
</div>
