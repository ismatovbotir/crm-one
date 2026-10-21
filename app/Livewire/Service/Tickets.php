<?php

namespace App\Livewire\Service;

use App\Enums\ServiceTicketStatus;
use App\Models\ServiceTicket;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Service > Tickets (Spec 09 §9.2): the queue with SLA timers (response / repair targets, overdue
 * shown as text), filters open / mine / overdue / waiting for parts / all, and search.
 */
#[Layout('layouts.app')]
class Tickets extends Component
{
    use WithPagination;

    #[Url(except: 'open')]
    public string $queue = 'open';

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ServiceTicket::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['queue', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $term = trim($this->search);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
        $open = ServiceTicketStatus::openValues();

        $tickets = ServiceTicket::query()->visibleTo(auth()->user())->with(['organization:id,name', 'asset.item:id,name', 'assignee:id,name'])
            ->when($this->queue === 'open', fn ($q) => $q->whereIn('status', $open))
            ->when($this->queue === 'mine', fn ($q) => $q->whereIn('status', $open)->where('assigned_to', auth()->id()))
            ->when($this->queue === 'waiting', fn ($q) => $q->where('status', ServiceTicketStatus::WaitingForParts->value))
            ->when($this->queue === 'overdue', fn ($q) => $q->whereIn('status', $open)->where(fn ($w) => $w
                ->where(fn ($r) => $r->where('status', ServiceTicketStatus::New->value)->where('respond_due_at', '<', now()))
                ->orWhere(fn ($r) => $r->whereNull('ready_at')->whereNotNull('repair_due_at')->where('repair_due_at', '<', now()))))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', $like)
                ->orWhereHas('asset', fn ($a) => $a->where('serial_normalized', 'like', '%'.mb_strtoupper($term).'%'))
                ->orWhereHas('organization', fn ($o) => $o->where('name', 'like', $like))))
            ->orderByRaw("case urgency when 'critical' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            // Spec 09 section 15: the waiting queue is FIFO within priority, with the manual order first.
            ->when($this->queue === 'waiting', fn ($q) => $q->orderByRaw('case when queue_rank is null then 1 else 0 end')->orderBy('queue_rank')->orderBy('queued_at'))
            ->orderBy('respond_due_at')->orderBy('id')
            ->paginate(config('crm.per_page'));

        return view('livewire.service.tickets', [
            'tickets' => $tickets,
            'queues' => ['open', 'mine', 'overdue', 'waiting', 'all'],
        ])->title(__('service.staff.tickets_title'));
    }
}
