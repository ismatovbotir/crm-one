<?php

namespace App\Services\Telegram\Internal;

use App\Enums\InternalEvent;
use App\Enums\Locale;
use App\Models\Order;
use App\Models\Requisition;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\TelegramChats;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Read-only bot commands in a company group (Spec 03 section 23.5): /status, /orders today,
 * /requests open, /report today. The sender must be an active staff user whose linked Telegram
 * id matches and who has the permission for that command; everyone else gets a polite refusal
 * without data. Replies are counts only (the group may include people with less access).
 * Commands never change data. Other messages in the group are ignored.
 */
class InternalCommands
{
    public const COMMANDS = ['status', 'orders', 'requests', 'report'];

    public const PER_CHAT_PER_MINUTE = 10;

    public function __construct(protected TelegramClient $client, protected ReportBuilder $reports) {}

    /**
     * @param  array<string, mixed>  $message
     * @return 'migrated'|'ignored'|'refused'|'answered'|'limited'
     */
    public function handle(array $message): string
    {
        $chatId = (int) data_get($message, 'chat.id');
        $chats = app(TelegramChats::class);

        if (isset($message['migrate_to_chat_id'])) {
            $chats->migrate($chatId, (int) $message['migrate_to_chat_id']);

            return 'migrated';
        }

        $chats->observeChat((array) ($message['chat'] ?? []));
        $command = $this->parse((string) ($message['text'] ?? ''));

        if ($command === null || (bool) data_get($message, 'from.is_bot', false)) {
            return 'ignored';
        }

        $key = 'tg-cmd|'.$chatId;

        if (RateLimiter::tooManyAttempts($key, self::PER_CHAT_PER_MINUTE)) {
            return 'limited';
        }

        RateLimiter::hit($key, 60);
        $thread = (bool) ($message['is_topic_message'] ?? false) ? (int) ($message['message_thread_id'] ?? 0) : null;
        $user = $this->staffUser((int) data_get($message, 'from.id'));
        $locale = ($user?->locale instanceof Locale ? $user->locale : Locale::default())->value;

        if ($user === null || ! $this->allowed($user, $command[0])) {
            $this->reply($chatId, $thread, e(__('telegram.internal.commands.refused', [], $locale)));

            return 'refused';
        }

        $this->reply($chatId, $thread, $this->answer($command[0], $locale));

        return 'answered';
    }

    /**
     * "/orders@crm_bot today" → ['orders', 'today']; only known commands, addressed to this bot.
     *
     * @return array{0: string, 1: string}|null
     */
    public function parse(string $text): ?array
    {
        if (preg_match('/^\/([a-z]+)(?:@([A-Za-z0-9_]+))?(?:\s+(\S+))?/u', trim($text), $matches) !== 1) {
            return null;
        }

        $bot = $this->client->botUsername();

        if (($matches[2] ?? '') !== '' && $bot !== null && strcasecmp($matches[2], ltrim($bot, '@')) !== 0) {
            return null;
        }

        return in_array($matches[1], self::COMMANDS, true) ? [$matches[1], mb_strtolower($matches[3] ?? '')] : null;
    }

    protected function staffUser(int $telegramUserId): ?User
    {
        if ($telegramUserId <= 0) {
            return null;
        }

        $user = TelegramLink::query()->with('user')->where('telegram_user_id', $telegramUserId)->usable()->first()?->user;

        return $user !== null && $user->is_active && $user->isInternal() ? $user : null;
    }

    protected function allowed(User $user, string $command): bool
    {
        return match ($command) {
            'status' => true,
            'orders' => $user->can('viewAny', Order::class),
            'requests' => $user->can('viewAny', Requisition::class),
            'report' => Gate::forUser($user)->allows('viewReports'),
            default => false,
        };
    }

    protected function answer(string $command, string $locale): string
    {
        $t = fn (string $key, array $params = []): string => e(__('telegram.internal.commands.'.$key, $params, $locale));
        $report = $this->reports->build();
        $sales = $report['sections']['sales'];

        $lines = match ($command) {
            'status' => [
                '<b>'.$t('status_title').'</b>',
                $t('open_leads', ['count' => $sales['detail_leads_open']]),
                $t('open_requests', ['count' => $this->reports->openRequests()]),
                $t('overdue_orders', ['count' => $report['sections']['receivables']['overdue_orders']]),
                $t('open_tickets', ['count' => $report['sections']['tickets']['tickets_open']]),
            ],
            'orders' => [
                '<b>'.$t('orders_title', ['date' => $report['period']]).'</b>',
                $t('orders_created', ['count' => $sales['orders_created']]),
                $t('orders_confirmed', ['count' => $sales['orders_confirmed']]),
                $t('orders_delivered', ['count' => $sales['orders_delivered']]),
            ],
            'requests' => [
                '<b>'.$t('requests_title').'</b>',
                $t('open_requests', ['count' => $this->reports->openRequests()]),
                $t('requests_today', ['count' => $sales['requests_received']]),
            ],
            default => [app(MessageRenderer::class)->render(InternalEvent::ReportDaily, null, $report, 'minimal', false, $locale)],
        };

        return implode("\n", $lines);
    }

    protected function reply(int $chatId, ?int $thread, string $html): void
    {
        try {
            foreach (MessageRenderer::split($html) as $part) {
                $this->client->sendMessage($chatId, $part, null, $thread);
            }
        } catch (TelegramException) {
            // Delivery problems are logged by the client; commands never change data.
        }
    }
}
