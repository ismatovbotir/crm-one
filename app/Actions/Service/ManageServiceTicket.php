<?php

namespace App\Actions\Service;

use App\Enums\AssetStatus;
use App\Enums\Locale;
use App\Enums\ServiceTicketStatus;
use App\Enums\ServiceUrgency;
use App\Enums\TelegramBotStatus;
use App\Models\CustomerAsset;
use App\Models\NumberSequence;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketPart;
use App\Models\StatusTransition;
use App\Models\TelegramChat;
use App\Models\User;
use App\Notifications\ServiceTicketOpened;
use App\Notifications\ServiceTicketUpdated;
use App\Services\Equipment\AssetRegister;
use App\Services\Equipment\Exceptions\EquipmentException;
use App\Services\Equipment\ServiceTeam;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\TelegramClient;
use App\Support\BusinessSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service ticket workflow (Spec 09 §5.2): open (from the portal or by staff), transitions with SLA
 * due times and the asset status kept in step, assignment, notes, parts and cost. Each status
 * change is logged (status_transitions + asset history) and notifies the customer (portal, e-mail,
 * Telegram) and the customer's linked Telegram groups through TelegramClient only. A billable
 * ticket closed outside warranty becomes `service_ticket.closed`, available for 1C to pull.
 */
class ManageServiceTicket
{
    public function __construct(protected AssetRegister $register) {}

    /**
     * @param  array{problem: string, urgency?: string|null, contact_name?: string|null, contact_phone?: string|null, location?: string|null}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function open(CustomerAsset $asset, array $data, User $reporter, string $source, array $photos = []): ServiceTicket
    {
        if ($asset->openTicket() !== null) {
            throw new EquipmentException('ticket_open');
        }

        if (! in_array($asset->status, [AssetStatus::InUse, AssetStatus::RepairedReady, AssetStatus::NeedsRepair], true)) {
            throw new EquipmentException('cannot_report');
        }

        if (count($photos) > (int) config('service.max_photos')) {
            throw new EquipmentException('too_many_photos', ['max' => (string) config('service.max_photos')]);
        }

        $ticket = DB::transaction(function () use ($asset, $data, $reporter, $source, $photos): ServiceTicket {
            $year = (int) CarbonImmutable::now((string) config('offers.timezone', 'Asia/Tashkent'))->format('Y');
            $prefix = (string) config('service.number_prefix', 'SV');
            $ticket = ServiceTicket::query()->create([
                'partner_id' => $asset->partner_id,
                'customer_asset_id' => $asset->id,
                'number' => NumberSequence::format($prefix, $year, NumberSequence::next($prefix, $year)),
                'status' => ServiceTicketStatus::New,
                'urgency' => ServiceUrgency::tryFrom((string) ($data['urgency'] ?? '')) ?? ServiceUrgency::Normal,
                'problem' => mb_substr(trim($data['problem']), 0, 5000),
                'contact_name' => filled($data['contact_name'] ?? null) ? mb_substr(trim((string) $data['contact_name']), 0, 255) : $reporter->name,
                'contact_phone' => filled($data['contact_phone'] ?? null) ? mb_substr(trim((string) $data['contact_phone']), 0, 32) : $reporter->phone,
                'location' => filled($data['location'] ?? null) ? mb_substr(trim((string) $data['location']), 0, 255) : $asset->location,
                'source' => $source,
                'reported_by' => $reporter->id,
                'respond_due_at' => now()->addHours(BusinessSettings::int('service.sla_response_hours')),
                'under_warranty' => $asset->underWarranty(),
                'warranty_until' => $asset->warranty_until,
                'currency' => (string) config('crm.base_currency', 'UZS'),
            ]);

            foreach ($photos as $photo) {
                $ticket->photos()->create(['path' => $this->register->storePhoto($photo, 'service-photos/'.$ticket->uuid), 'uploaded_by' => $reporter->id, 'created_at' => now()]);
            }

            $this->transitionLog($ticket, null, ServiceTicketStatus::New, $reporter, null);

            if ($asset->status !== AssetStatus::NeedsRepair) {
                $this->register->changeStatus($asset, AssetStatus::NeedsRepair, $reporter, $ticket->problem, $ticket);
            }

            $this->register->event($asset, 'ticket_opened', null, null, $ticket->number, $ticket, $reporter);

            return $ticket;
        });

        $recipients = ServiceTeam::members();
        $owner = $ticket->customer?->owner;

        if ($owner !== null && $owner->is_active && ! $recipients->contains('id', $owner->id)) {
            $recipients->push($owner);
        }

        foreach ($recipients as $recipient) {
            $recipient->notify(new ServiceTicketOpened($ticket));
        }

        return $ticket;
    }

    /**
     * Staff transition with the asset kept in step (Spec 09 §5.1).
     *
     * @param  array{reason?: string|null, resolution?: string|null, comment?: string|null}  $data
     */
    public function transition(ServiceTicket $ticket, ServiceTicketStatus $to, User $user, array $data = []): ServiceTicket
    {
        $from = $ticket->status;

        if (! $from->canTransitionTo($to)) {
            throw new EquipmentException('invalid_ticket_transition', ['from' => $from->label(), 'to' => $to->label()]);
        }

        if ($to === ServiceTicketStatus::Rejected && blank($data['reason'] ?? null)) {
            throw new EquipmentException('reason_required');
        }

        if ($to === ServiceTicketStatus::Closed && blank($data['resolution'] ?? null) && blank($ticket->resolution)) {
            throw new EquipmentException('resolution_required');
        }

        DB::transaction(function () use ($ticket, $from, $to, $user, $data): void {
            $attributes = ['status' => $to];

            match ($to) {
                ServiceTicketStatus::Accepted => $attributes += ['accepted_at' => now(), 'repair_due_at' => now()->addHours(BusinessSettings::int('service.sla_repair_hours')), 'assigned_to' => $ticket->assigned_to ?? $user->id],
                ServiceTicketStatus::Ready => $attributes += ['ready_at' => now()],
                ServiceTicketStatus::Closed => $attributes += ['closed_at' => now(), 'resolution' => filled($data['resolution'] ?? null) ? mb_substr((string) $data['resolution'], 0, 5000) : $ticket->resolution],
                ServiceTicketStatus::Rejected => $attributes += ['closed_at' => now(), 'reject_reason' => mb_substr((string) $data['reason'], 0, 2000)],
                ServiceTicketStatus::Cancelled => $attributes += ['closed_at' => now()],
                default => null,
            };

            // Spec 09 section 15: entering / leaving "Waiting in queue (out of stock)".
            if ($to === ServiceTicketStatus::WaitingForParts) {
                $attributes += [
                    'queued_at' => now(),
                    'queue_rank' => null,
                    'queue_need' => filled($data['queue_need'] ?? null) ? mb_substr(trim((string) $data['queue_need']), 0, 255) : ($data['comment'] ?? null),
                    'queue_item_id' => isset($data['queue_item_id']) && $data['queue_item_id'] !== '' ? (int) $data['queue_item_id'] : null,
                    'queue_eta' => filled($data['queue_eta'] ?? null) ? (string) $data['queue_eta'] : null,
                ];
            } elseif ($from === ServiceTicketStatus::WaitingForParts) {
                $attributes += ['queued_at' => null, 'queue_rank' => null, 'queue_eta' => null];
            }

            $ticket->forceFill($attributes)->save();
            $comment = $data['reason'] ?? $data['resolution'] ?? $data['comment'] ?? null;
            $this->transitionLog($ticket, $from, $to, $user, $comment);
            $this->syncAsset($ticket, $to, $user, $comment);

            if ($to === ServiceTicketStatus::Closed && $ticket->fresh(['parts'])->isBillable()) {
                app(OneCOutbox::class)->publishServiceTicket($ticket->fresh(['parts', 'asset.item', 'customer']));
            }
        });

        $this->notifyCustomer($ticket->fresh(), $user);

        return $ticket;
    }

    /** The customer withdraws the request while it was not worked on yet. */
    public function cancelByCustomer(ServiceTicket $ticket, User $user): void
    {
        if (! $ticket->status->cancellableByCustomer()) {
            throw new EquipmentException('cannot_cancel');
        }

        $this->transition($ticket, ServiceTicketStatus::Cancelled, $user, ['comment' => __('service.cancelled_by_customer')]);
    }

    /** Needed item / text and estimated date of a queued ticket (Spec 09 section 15). */
    public function setQueueDetails(ServiceTicket $ticket, ?string $need, ?int $itemId, ?string $eta, User $by): void
    {
        if ($ticket->status !== ServiceTicketStatus::WaitingForParts) {
            throw new EquipmentException('not_queued');
        }

        $ticket->forceFill([
            'queue_need' => filled($need) ? mb_substr(trim((string) $need), 0, 255) : null,
            'queue_item_id' => $itemId,
            'queue_eta' => filled($eta) ? $eta : null,
        ])->save();
        $ticket->logActivity('queue_details', ['need' => $ticket->queue_need, 'eta' => $ticket->queue_eta?->toDateString(), 'by' => $by->name]);
    }

    public function assign(ServiceTicket $ticket, ?User $engineer, User $by): void
    {
        $ticket->forceFill(['assigned_to' => $engineer?->id])->save();
        $ticket->logActivity('assigned', ['to' => $engineer?->name, 'by' => $by->name]);
    }

    public function addNote(ServiceTicket $ticket, User $user, string $body, bool $internal): void
    {
        $ticket->notes()->create(['user_id' => $user->id, 'body' => mb_substr(trim($body), 0, 5000), 'is_internal' => $internal, 'created_at' => now()]);

        if (! $internal) {
            $this->notifyCustomer($ticket, $user, $body);
        }
    }

    /**
     * @param  array{item_id?: int|null, description: string, quantity: string, unit_price?: string|null}  $data
     */
    public function addPart(ServiceTicket $ticket, array $data, User $user): ServiceTicketPart
    {
        if (! $ticket->status->isOpen()) {
            throw new EquipmentException('ticket_closed');
        }

        $price = filled($data['unit_price'] ?? null) ? Money::fromMajor((string) $data['unit_price'], (string) $ticket->currency)->amount : 0;

        return $ticket->parts()->create([
            'item_id' => $data['item_id'] ?? null,
            'description' => mb_substr(trim($data['description']), 0, 255),
            'quantity' => $data['quantity'],
            'unit_price_minor' => $ticket->under_warranty ? 0 : $price,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Labour cost (outside warranty) and the replacement unit lent, if any.
     */
    public function setWork(ServiceTicket $ticket, ?string $labor, ?string $replacementNote, User $user): void
    {
        $ticket->forceFill([
            'labor_minor' => $ticket->under_warranty || blank($labor) ? null : Money::fromMajor((string) $labor, (string) $ticket->currency)->amount,
            'replacement_note' => filled($replacementNote) ? mb_substr((string) $replacementNote, 0, 2000) : null,
        ])->save();
    }

    protected function syncAsset(ServiceTicket $ticket, ServiceTicketStatus $to, User $user, ?string $comment): void
    {
        $asset = $ticket->asset;
        $target = match ($to) {
            ServiceTicketStatus::Accepted => AssetStatus::InRepair,
            ServiceTicketStatus::Ready => AssetStatus::RepairedReady,
            ServiceTicketStatus::Closed => AssetStatus::InUse,
            ServiceTicketStatus::Rejected, ServiceTicketStatus::Cancelled => in_array($asset->status, [AssetStatus::NeedsRepair, AssetStatus::InRepair], true) ? AssetStatus::InUse : null,
            default => null,
        };

        if ($target !== null && $asset->status !== $target && $asset->status->canTransitionTo($target)) {
            $this->register->changeStatus($asset, $target, $user, $comment, $ticket);
        } else {
            $this->register->event($asset, 'ticket_status', null, null, $to->label().($comment ? ': '.$comment : ''), $ticket, $user);
        }
    }

    protected function transitionLog(ServiceTicket $ticket, ?ServiceTicketStatus $from, ServiceTicketStatus $to, ?User $user, ?string $reason): void
    {
        StatusTransition::query()->create([
            'subject_type' => $ticket->getMorphClass(),
            'subject_id' => $ticket->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $user?->id,
            'reason' => filled($reason) ? mb_substr((string) $reason, 0, 1000) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Portal users of the organization (and the reporter) plus the customer's linked Telegram groups.
     */
    protected function notifyCustomer(ServiceTicket $ticket, User $by, ?string $note = null): void
    {
        $ticket->loadMissing(['asset.item', 'organization']);

        foreach ($this->portalUsers($ticket) as $user) {
            if ($user->id !== $by->id) {
                $user->notify(new ServiceTicketUpdated($ticket, $note));
            }
        }

        $client = app(TelegramClient::class);

        // Spec 09 section 14: group members may be outside the buyer team, so only the ticket
        // number and status go out (no problem text, asset or photos), and both the global and the
        // per-customer switch can turn it off.
        if (! $client->enabled() || ! BusinessSettings::bool('service.telegram_group_updates') || ! (bool) ($ticket->organization?->telegram_service_updates ?? true)) {
            return;
        }

        $text = e(__('service.telegram.update', [
            'number' => $ticket->number,
            'status' => $ticket->status->label(),
        ], Locale::default()->value));

        foreach (TelegramChat::query()->groups()->where('customer_id', $ticket->partner_id)->whereNull('merged_into_id')->where('bot_status', '!=', TelegramBotStatus::Removed->value)->get() as $chat) {
            try {
                $client->sendMessage($chat->chat_id, $text);
            } catch (TelegramException) {
                // Delivery problems are logged by the client; the ticket is updated either way.
            }
        }
    }

    /**
     * @return Collection<int, User>
     */
    protected function portalUsers(ServiceTicket $ticket): Collection
    {
        return User::query()->where('is_active', true)
            ->whereHas('portalMemberships', fn ($q) => $q->where('customer_id', $ticket->partner_id)->where('is_active', true))
            ->get();
    }
}
