<?php

namespace App\Actions\Telegram;

use App\Actions\Leads\LeadWorkflow;
use App\Enums\InternalEvent;
use App\Enums\LeadCloseReason;
use App\Enums\LeadStatus;
use App\Enums\Locale;
use App\Enums\TelegramBotStatus;
use App\Enums\TelegramChatPurpose;
use App\Enums\TelegramChatType;
use App\Models\Lead;
use App\Models\TelegramChat;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\Telegram\Internal\InternalTelegramException;
use App\Services\Telegram\TelegramChats;
use Illuminate\Support\Facades\DB;

/**
 * Settings of the internal Telegram notifications (Spec 03 section 23.1): destinations (company
 * group + optional topic, language, amounts, routing matrix) and the chat purpose. Marking a chat
 * internal closes a lead opened from it by mistake only when the user confirms (closed as
 * disqualified, never deleted) and detaches it from any customer. Every change is audited.
 */
class ManageInternalTelegram
{
    /**
     * @param  array{name: string, chat_id: int|string, message_thread_id?: int|string|null, locale?: string, include_amounts?: bool, is_active?: bool}  $data
     */
    public function saveDestination(array $data, User $by, ?TelegramDestination $destination = null, bool $confirmLeadClose = false): TelegramDestination
    {
        $chatId = (int) $data['chat_id'];

        if ($chatId === 0) {
            throw new InternalTelegramException('chat_id_invalid');
        }

        $thread = filled($data['message_thread_id'] ?? null) ? (int) $data['message_thread_id'] : null;

        if ($thread !== null && $thread <= 0) {
            throw new InternalTelegramException('thread_invalid');
        }

        return DB::transaction(function () use ($data, $by, $destination, $confirmLeadClose, $chatId, $thread): TelegramDestination {
            $chat = TelegramChat::query()->where('chat_id', $chatId)->first() ?? TelegramChat::query()->create([
                'chat_id' => $chatId,
                'type' => $chatId < 0 ? TelegramChatType::Supergroup : TelegramChatType::Private,
                'title' => mb_substr(trim((string) $data['name']), 0, 255),
                'bot_status' => TelegramBotStatus::Member,
                'first_seen_at' => now(),
                'purpose' => TelegramChatPurpose::InternalNotifications,
            ]);

            if ($chat->type === TelegramChatType::Private) {
                throw new InternalTelegramException('private_chat');
            }

            $this->markInternal($chat, $by, $confirmLeadClose);

            $destination ??= new TelegramDestination(['events' => TelegramDestination::defaultEvents(), 'created_by' => $by->id]);
            $destination->fill([
                'name' => mb_substr(trim((string) $data['name']), 0, 120),
                'chat_id' => $chat->chat_id,
                'message_thread_id' => $thread,
                'locale' => Locale::tryFrom((string) ($data['locale'] ?? 'uz'))?->value ?? Locale::Uz->value,
                'include_amounts' => (bool) ($data['include_amounts'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'updated_by' => $by->id,
            ])->save();

            return $destination;
        });
    }

    /**
     * @param  array<string, string|null>  $events  event value => minimal|standard|'' (off)
     */
    public function saveRouting(TelegramDestination $destination, array $events, User $by): void
    {
        $clean = [];

        foreach (InternalEvent::cases() as $event) {
            $level = $events[$event->value] ?? null;

            if (in_array($level, TelegramDestination::LEVELS, true)) {
                $clean[$event->value] = $level;
            }
        }

        $destination->forceFill(['events' => $clean, 'updated_by' => $by->id])->save();
    }

    public function deleteDestination(TelegramDestination $destination, User $by): void
    {
        $destination->logActivity('destination_deleted', ['user' => $by->name, 'name' => $destination->name]);
        $destination->delete();
    }

    /**
     * Turns a group into a company group. An open lead from it needs the user's confirmation and
     * is then closed as disqualified ("wrong target"), never deleted.
     */
    public function markInternal(TelegramChat $chat, User $by, bool $confirmLeadClose): void
    {
        if ($chat->isInternal()) {
            return;
        }

        $openLeads = Lead::query()->where('telegram_chat_id', $chat->chat_id)->whereIn('status', LeadStatus::openValues())->get();

        if ($openLeads->isNotEmpty() && ! $confirmLeadClose) {
            throw new InternalTelegramException('open_lead', ['numbers' => $openLeads->pluck('number')->join(', ')]);
        }

        foreach ($openLeads as $lead) {
            app(LeadWorkflow::class)->close($lead, $by, LeadStatus::Disqualified, LeadCloseReason::WrongTarget, __('telegram.internal.lead_closed_note'));
        }

        $previousCustomer = $chat->customer_id;
        $chat->forceFill(['purpose' => TelegramChatPurpose::InternalNotifications, 'lead_id' => null, 'customer_id' => null])->save();
        app(TelegramChats::class)->audit($chat, 'telegram_chat_marked_internal', ['by' => $by->name, 'closed_leads' => $openLeads->pluck('number')->all(), 'previous_customer_id' => $previousCustomer]);
    }

    /** Back to a customer chat; refused while a destination still posts to it. */
    public function markCustomer(TelegramChat $chat, User $by): void
    {
        if (TelegramDestination::query()->where('chat_id', $chat->chat_id)->exists()) {
            throw new InternalTelegramException('chat_in_use');
        }

        $chat->forceFill(['purpose' => TelegramChatPurpose::Customer])->save();
        app(TelegramChats::class)->audit($chat, 'telegram_chat_marked_customer', ['by' => $by->name]);
    }
}
