<?php

namespace App\Actions\Leads;

use App\Enums\LeadActivityType;
use App\Enums\LeadCloseReason;
use App\Enums\LeadStatus;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\TelegramChat;
use App\Models\User;
use App\Services\Leads\Exceptions\LeadActionException;
use App\Services\Telegram\TelegramChats;
use Illuminate\Support\Facades\DB;

/**
 * Lead steps other than capture and conversion (Spec 04 sections 5 and 7): mark contacted, qualify
 * behind the BR-4 gate, close with a reason (BR-5), reopen with a comment, reassign, log an
 * activity, link an existing customer and contact. Every status change goes through TransitionLead.
 */
class LeadWorkflow
{
    public function __construct(protected TransitionLead $transition) {}

    public function markContacted(Lead $lead, User $user): Lead
    {
        return $this->locked($lead, fn (Lead $fresh): Lead => $this->transition->handle($fresh, LeadStatus::Contacted, $user));
    }

    public function qualify(Lead $lead, User $user): Lead
    {
        return $this->locked($lead, function (Lead $fresh) use ($user): Lead {
            $gaps = $fresh->qualificationGaps();

            if ($gaps !== []) {
                throw new LeadActionException('not_qualifiable', ['missing' => collect($gaps)->map(fn (string $gap): string => __('leads.gate.'.$gap))->join(', ')]);
            }

            return $this->transition->handle($fresh, LeadStatus::Qualified, $user);
        });
    }

    public function close(Lead $lead, User $user, LeadStatus $to, ?LeadCloseReason $reason, ?string $note): Lead
    {
        $note = blank($note) ? null : trim((string) $note);

        if (! in_array($to, [LeadStatus::Disqualified, LeadStatus::Lost], true)) {
            throw new LeadActionException('invalid_transition', ['from' => $lead->status->label(), 'to' => $to->label()]);
        }

        if ($reason === null) {
            throw new LeadActionException('reason_required');
        }

        if ($reason === LeadCloseReason::Other && mb_strlen((string) $note) < (int) config('leads.min_note_length', 10)) {
            throw new LeadActionException('note_required', ['min' => (int) config('leads.min_note_length', 10)]);
        }

        return $this->locked($lead, fn (Lead $fresh): Lead => $this->transition->handle($fresh, $to, $user, 'close:'.$reason->value.($note !== null ? ' — '.$note : ''), [
            'close_reason' => $reason,
            'close_note' => $note,
        ]));
    }

    public function reopen(Lead $lead, User $user, ?string $comment): Lead
    {
        $comment = trim((string) $comment);

        if (mb_strlen($comment) < (int) config('leads.min_note_length', 10)) {
            throw new LeadActionException('comment_required', ['min' => (int) config('leads.min_note_length', 10)]);
        }

        return $this->locked($lead, function (Lead $fresh) use ($user, $comment): Lead {
            if ($fresh->anonymised_at !== null) {
                throw new LeadActionException('anonymised');
            }

            return $this->transition->handle($fresh, LeadStatus::Contacted, $user, 'reopened:'.$comment);
        });
    }

    public function assign(Lead $lead, User $by, User $owner): Lead
    {
        if (! $owner->is_active || ! $owner->isInternal() || ! $owner->can('create', Lead::class)) {
            throw new LeadActionException('owner_invalid');
        }

        return $this->locked($lead, function (Lead $fresh) use ($owner): Lead {
            if (! $fresh->status->isOpen()) {
                throw new LeadActionException('read_only');
            }

            $fresh->update(['owner_id' => $owner->id]);

            return $fresh;
        });
    }

    public function logActivity(Lead $lead, User $user, LeadActivityType $type, string $text): Lead
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > (int) config('leads.activity_max_length', 2000)) {
            throw new LeadActionException('activity_text');
        }

        return DB::transaction(function () use ($lead, $type, $text): Lead {
            $lead->logActivity('lead_activity', ['type' => $type->value, 'text' => $text]);
            $lead->forceFill(['last_activity_at' => now()])->saveQuietly();

            return $lead;
        });
    }

    /**
     * Link an existing customer (and optionally one of its contacts) to an open lead (BR-3 "use
     * this customer", or after "create customer from lead").
     */
    public function linkCustomer(Lead $lead, Partner $customer, ?Contact $contact = null): Lead
    {
        if ($customer->trashed() || $customer->isDeletedInOneC()) {
            throw new LeadActionException('customer_unavailable');
        }

        if ($contact !== null && $contact->partner_id !== $customer->id) {
            throw new LeadActionException('contact_mismatch');
        }

        $linked = $this->locked($lead, function (Lead $fresh) use ($customer, $contact): Lead {
            if (! $fresh->status->isOpen()) {
                throw new LeadActionException('read_only');
            }

            $keepContact = $fresh->contact?->partner_id === $customer->id ? $fresh->contact_id : null;
            $fresh->update(['customer_id' => $customer->id, 'contact_id' => $contact?->id ?? $keepContact ?? $customer->primaryContact?->id, 'currency' => $fresh->currency ?: $customer->currency]);

            return $fresh;
        });

        // Spec 04 section 17: a group lead's company is the group's company ("Create company from group").
        $chat = $linked->telegram_chat_id !== null && $linked->telegram_chat_id < 0 ? TelegramChat::query()->where('chat_id', $linked->telegram_chat_id)->first() : null;

        if ($chat !== null && $chat->customer_id === null && auth()->user() !== null) {
            app(TelegramChats::class)->linkCustomer($chat, $customer, auth()->user());
        }

        return $linked;
    }

    /**
     * @param  callable(Lead): Lead  $callback
     */
    protected function locked(Lead $lead, callable $callback): Lead
    {
        return DB::transaction(function () use ($lead, $callback): Lead {
            $fresh = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            $result = $callback($fresh);
            $lead->setRawAttributes($result->getAttributes(), true);

            return $result;
        });
    }
}
