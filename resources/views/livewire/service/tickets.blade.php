<div class="space-y-6" data-service-tickets>
    <div class="card flex flex-wrap items-end gap-3 p-4">
        <div role="group" aria-label="{{ __('service.staff.queue') }}" class="flex flex-wrap gap-1">
            @foreach ($queues as $key)
                <button type="button" wire:click="$set('queue', '{{ $key }}')" aria-pressed="{{ $queue === $key ? 'true' : 'false' }}" @class(['btn btn-sm', 'btn-primary' => $queue === $key, 'btn-secondary' => $queue !== $key]) data-queue="{{ $key }}">{{ __('service.staff.queues.'.$key) }}</button>
            @endforeach
        </div>
        <div class="min-w-48 flex-1">
            <label for="st-search" class="sr-only">{{ __('common.search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="st-search" type="search" class="form-input" placeholder="{{ __('service.staff.search') }}">
        </div>
        <a href="{{ route('service.assets') }}" wire:navigate class="btn btn-secondary btn-sm">{{ __('service.staff.lookup') }}</a>
    </div>

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.number') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.customer') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.item') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.urgency') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.sla') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.assignee') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($tickets as $ticket)
                    @php($due = $ticket->status === \App\Enums\ServiceTicketStatus::New ? $ticket->respond_due_at : ($ticket->ready_at === null && $ticket->status->isOpen() ? $ticket->repair_due_at : null))
                    <tr wire:key="tk-{{ $ticket->id }}" data-ticket="{{ $ticket->number }}">
                        <td class="px-4 py-3"><a href="{{ route('service.tickets.show', $ticket) }}" wire:navigate class="link font-mono">{{ $ticket->number }}</a></td>
                        <td class="px-4 py-3 text-ink">{{ $ticket->organization?->name }}</td>
                        <td class="px-4 py-3 text-ink">{{ $ticket->asset->displayName() }}<span class="block font-mono text-xs text-muted">{{ $ticket->asset->serial_raw }}</span></td>
                        <td class="px-4 py-3"><x-service.ticket-badge :ticket="$ticket" />@if ($ticket->under_warranty)<span class="block text-xs text-muted">{{ __('service.under_warranty') }}</span>@endif</td>
                        <td class="px-4 py-3 text-ink">{{ $ticket->urgency->label() }}</td>
                        <td class="px-4 py-3 whitespace-nowrap" data-sla>
                            @if ($due)
                                @if ($due->isPast())
                                    <span class="font-semibold text-brand-700">{{ __('service.staff.overdue_by', ['time' => $due->diffForHumans(null, true)]) }}</span>
                                @else
                                    <span class="text-ink">{{ __('service.staff.due_in', ['time' => $due->diffForHumans(null, true)]) }}</span>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink">{{ $ticket->assignee?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-0"><x-empty-state :title="__('service.staff.empty')" icon="check" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($tickets->hasPages())<div class="border-t border-line p-4">{{ $tickets->links() }}</div>@endif
    </div>
</div>
