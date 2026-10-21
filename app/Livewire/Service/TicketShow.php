<?php

namespace App\Livewire\Service;

use App\Actions\Service\ManageServiceTicket;
use App\Enums\ServiceTicketStatus;
use App\Models\Item;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Services\Attachments\StoreAttachment;
use App\Services\Equipment\Exceptions\EquipmentException;
use App\Services\Equipment\ServiceQueue;
use App\Services\Equipment\ServiceTeam;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Service ticket page (Spec 09 §9.2): details, warranty, SLA, transitions (reason / resolution),
 * assignment, internal or public notes, parts used, labour cost outside warranty, replacement unit
 * note, documents, receipt print. Only `service.manage` changes anything; others read.
 */
#[Layout('layouts.app')]
class TicketShow extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $ticketId;

    public string $target = '';

    public string $transitionText = '';

    public string $note = '';

    public bool $noteInternal = true;

    public ?int $assignee = null;

    /** @var array{item_id: string, description: string, quantity: string, unit_price: string} */
    public array $part = ['item_id' => '', 'description' => '', 'quantity' => '1', 'unit_price' => ''];

    public string $partSearch = '';

    public string $labor = '';

    public string $replacementNote = '';

    /** Spec 09 section 15: queue details (needed part/consumable/unit, estimated date) and reorder. */
    public string $queueNeed = '';

    public ?int $queueItemId = null;

    public string $queueEta = '';

    public string $queuePosition = '';

    public string $queueReason = '';

    /** @var TemporaryUploadedFile|null */
    public $document = null;

    public function mount(ServiceTicket $ticket): void
    {
        $this->authorize('view', $ticket);
        $this->ticketId = $ticket->id;
        $this->assignee = $ticket->assigned_to;
        $this->labor = $ticket->labor_minor !== null ? $ticket->money($ticket->labor_minor)->toMajor() : '';
        $this->replacementNote = (string) $ticket->replacement_note;
        $this->queueNeed = (string) $ticket->queue_need;
        $this->queueItemId = $ticket->queue_item_id;
        $this->queueEta = (string) $ticket->queue_eta?->toDateString();
    }

    #[Computed]
    public function ticket(): ServiceTicket
    {
        return ServiceTicket::query()->with(['organization', 'asset.item', 'asset.itemSerial', 'reporter', 'assignee', 'parts.item', 'notes.user', 'photos', 'attachments', 'transitions.user'])->findOrFail($this->ticketId);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function engineers(): Collection
    {
        return ServiceTeam::members();
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function partOptions(): Collection
    {
        $term = trim($this->partSearch);

        return mb_strlen($term) < 2 ? collect() : Item::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('sku', 'like', '%'.$term.'%'))->orderBy('name')->limit(20)->get(['id', 'name', 'sku']);
    }

    public function moveTicket(ManageServiceTicket $tickets): void
    {
        $this->authorize('manage', $this->ticket);
        $to = ServiceTicketStatus::tryFrom($this->target);

        if ($to === null) {
            $this->addError('target', __('service.errors.choose_status'));

            return;
        }

        $data = match ($to) {
            ServiceTicketStatus::Rejected => ['reason' => $this->transitionText],
            ServiceTicketStatus::Closed => ['resolution' => $this->transitionText],
            ServiceTicketStatus::WaitingForParts => ['comment' => $this->transitionText, 'queue_need' => $this->queueNeed, 'queue_item_id' => $this->queueItemId, 'queue_eta' => $this->queueEta],
            default => ['comment' => $this->transitionText],
        };

        if ($to === ServiceTicketStatus::WaitingForParts) {
            $this->validate(['queueEta' => ['nullable', 'date', 'after_or_equal:today'], 'queueNeed' => ['nullable', 'string', 'max:255']], [], ['queueEta' => __('service.queue.eta'), 'queueNeed' => __('service.queue.need')]);
        }

        $this->run(fn () => $tickets->transition($this->ticket, $to, auth()->user(), $data), 'target');
        $this->reset('target', 'transitionText');
    }

    public function saveQueueDetails(ManageServiceTicket $tickets): void
    {
        $this->authorize('manage', $this->ticket);
        $this->validate(['queueEta' => ['nullable', 'date'], 'queueNeed' => ['nullable', 'string', 'max:255'], 'queueItemId' => ['nullable', 'integer', 'exists:items,id']], [], ['queueEta' => __('service.queue.eta'), 'queueNeed' => __('service.queue.need')]);
        $this->run(fn () => $tickets->setQueueDetails($this->ticket, $this->queueNeed, $this->queueItemId, $this->queueEta, auth()->user()), 'queueNeed');
    }

    public function reorderQueue(ServiceQueue $queue): void
    {
        $this->authorize('manage', $this->ticket);
        $this->validate(['queuePosition' => ['required', 'integer', 'min:1'], 'queueReason' => ['required', 'string', 'min:5', 'max:500']], [], ['queuePosition' => __('service.queue.position_field'), 'queueReason' => __('service.queue.reason')]);
        $this->run(fn () => $queue->reorder($this->ticket, (int) $this->queuePosition, $this->queueReason, auth()->user()), 'queuePosition');
        $this->reset('queuePosition', 'queueReason');
    }

    public function assign(ManageServiceTicket $tickets): void
    {
        $this->authorize('manage', $this->ticket);
        $engineer = $this->assignee !== null ? $this->engineers->firstWhere('id', $this->assignee) : null;
        $tickets->assign($this->ticket, $engineer, auth()->user());
        unset($this->ticket);
        session()->flash('status', __('service.flash.assigned'));
    }

    public function addNote(ManageServiceTicket $tickets): void
    {
        $this->authorize('manage', $this->ticket);
        $this->validate(['note' => ['required', 'string', 'max:5000']], [], ['note' => __('service.fields.note')]);
        $tickets->addNote($this->ticket, auth()->user(), $this->note, $this->noteInternal);
        $this->reset('note');
        $this->noteInternal = true;
        unset($this->ticket);
    }

    public function addPart(ManageServiceTicket $tickets): void
    {
        $this->authorize('manage', $this->ticket);
        $this->validate([
            'part.item_id' => ['nullable', 'integer'],
            'part.description' => ['required_without:part.item_id', 'nullable', 'string', 'max:255'],
            'part.quantity' => ['required', 'regex:/^\d{1,9}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'part.unit_price' => ['nullable', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
        ], [], ['part.description' => __('service.fields.part'), 'part.quantity' => __('service.fields.quantity')]);

        $item = $this->part['item_id'] !== '' ? Item::query()->find((int) $this->part['item_id']) : null;
        $this->run(fn () => $tickets->addPart($this->ticket, [
            'item_id' => $item?->id,
            'description' => $this->part['description'] !== '' ? $this->part['description'] : (string) $item?->name,
            'quantity' => $this->part['quantity'],
            'unit_price' => $this->part['unit_price'] !== '' ? $this->part['unit_price'] : ($item?->sale_price_minor !== null ? $this->ticket->money((int) $item->sale_price_minor)->toMajor() : null),
        ], auth()->user()), 'part.description');
        $this->reset('part', 'partSearch');
    }

    public function saveWork(ManageServiceTicket $tickets): void
    {
        $this->authorize('manage', $this->ticket);
        $this->validate(['labor' => ['nullable', 'regex:/^\d{1,15}(\.\d{1,2})?$/'], 'replacementNote' => ['nullable', 'string', 'max:2000']]);
        $tickets->setWork($this->ticket, $this->labor, $this->replacementNote, auth()->user());
        unset($this->ticket);
        session()->flash('status', __('service.flash.saved'));
    }

    public function attach(StoreAttachment $attachments): void
    {
        $this->authorize('manage', $this->ticket);
        $this->validate(['document' => ['required', 'file', 'max:10240']]);
        $attachments->handle($this->document, $this->ticket, 'service_document', auth()->user(), 10240, 'document');
        $this->reset('document');
        unset($this->ticket);
        session()->flash('status', __('service.flash.saved'));
    }

    protected function run(callable $action, string $errorField): void
    {
        try {
            $action();
            session()->flash('status', __('service.flash.saved'));
        } catch (EquipmentException $exception) {
            $this->addError($errorField, $exception->getMessage());
        }

        unset($this->ticket);
    }

    public function render(): View
    {
        $ticket = $this->ticket;

        return view('livewire.service.ticket-show', [
            'ticket' => $ticket,
            'canManage' => auth()->user()->can('manage', $ticket),
            'queuePositionNow' => app(ServiceQueue::class)->position($ticket),
            'targets' => collect($ticket->status->allowedTargets())->mapWithKeys(fn (ServiceTicketStatus $s): array => [$s->value => $s->label()])->all(),
        ])->title($ticket->number);
    }
}
