<div class="space-y-6" data-requisitions>
    <div class="card flex flex-wrap items-end gap-3 p-4">
        <div role="group" aria-label="{{ __('requisitions.staff.queue') }}" class="flex flex-wrap gap-1">
            @foreach ($queues as $key)
                <button type="button" wire:click="$set('queue', '{{ $key }}')" aria-pressed="{{ $queue === $key ? 'true' : 'false' }}" @class(['btn btn-sm', 'btn-primary' => $queue === $key, 'btn-secondary' => $queue !== $key])>{{ __('requisitions.staff.queues.'.$key) }}</button>
            @endforeach
        </div>
        <div class="min-w-48 flex-1"><label for="rqi-search" class="sr-only">{{ __('common.search') }}</label><input wire:model.live.debounce.300ms="search" id="rqi-search" type="search" class="form-input" placeholder="{{ __('requisitions.staff.search') }}"></div>
    </div>
    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('requisitions.fields.number') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('requisitions.fields.customer') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                    <th scope="col" class="px-4 py-3 text-right">{{ __('requisitions.fields.lines') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('requisitions.fields.owner') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('requisitions.fields.sent_at') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($requisitions as $requisition)
                    <tr wire:key="rqi-{{ $requisition->id }}">
                        <td class="px-4 py-3"><a href="{{ route('requisitions.show', $requisition) }}" wire:navigate class="link font-mono">{{ $requisition->number }}</a><span class="block text-xs text-muted">{{ $requisition->title }}</span></td>
                        <td class="px-4 py-3 text-ink">{{ $requisition->organization?->name }}</td>
                        <td class="px-4 py-3"><x-requisitions.status-badge :status="$requisition->status" /></td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $requisition->lines_count }}</td>
                        <td class="px-4 py-3 text-ink">{{ $requisition->owner?->name ?? __('requisitions.unassigned') }}</td>
                        <td class="px-4 py-3 text-ink">{{ $requisition->sent_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-0"><x-empty-state :title="__('requisitions.staff.empty')" icon="check" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($requisitions->hasPages())<div class="border-t border-line p-4">{{ $requisitions->links() }}</div>@endif
    </div>
</div>
