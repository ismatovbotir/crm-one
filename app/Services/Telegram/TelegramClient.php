<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use App\Services\Telegram\Exceptions\TelegramException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only outbound HTTP caller in the application (Spec 03 4.6): Telegram Bot API at
 * api.telegram.org. Disabled unless Settings > Telegram bot holds a token and is switched on.
 * Timeouts 5/10 s, one retry on connection errors and 5xx, typed failures (blocked, chat not
 * found, rate limited, unauthorized). Logs only method, status and duration, never text or codes.
 * The ERP integration stays pull-only (Spec 02): the architecture test allows Http only in this class.
 */
class TelegramClient
{
    public const API = 'https://api.telegram.org';

    public function enabled(): bool
    {
        return Setting::get('telegram.enabled') === '1' && $this->token() !== null;
    }

    public function botUsername(): ?string
    {
        return Setting::get('telegram.bot_username');
    }

    public function webhookSecret(): ?string
    {
        return Setting::get('telegram.webhook_secret');
    }

    public function sendMessage(int $chatId, string $html, ?array $replyMarkup = null, ?int $messageThreadId = null): void
    {
        $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'message_thread_id' => $messageThreadId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $replyMarkup !== null ? json_encode($replyMarkup) : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function getMe(): array
    {
        return $this->call('getMe', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function setWebhook(string $url, string $secret): array
    {
        return $this->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => json_encode(['message', 'edited_message', 'my_chat_member', 'chat_member'])]);
    }

    /**
     * Administrators of a group (Spec 04 section 17, on demand only).
     *
     * @return list<array<string, mixed>>
     */
    public function getChatAdministrators(int $chatId): array
    {
        return array_values($this->call('getChatAdministrators', ['chat_id' => $chatId]));
    }

    public function getChatMemberCount(int $chatId): ?int
    {
        $result = $this->callScalar('getChatMemberCount', ['chat_id' => $chatId]);

        return is_numeric($result) ? (int) $result : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo', []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function call(string $method, array $payload): array
    {
        return (array) $this->callScalar($method, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function callScalar(string $method, array $payload): mixed
    {
        $token = $this->token();

        if ($token === null) {
            throw new TelegramException('disabled');
        }

        $started = microtime(true);

        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(10)
                ->retry(2, 500, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->post(self::API.'/bot'.$token.'/'.$method, $payload);
        } catch (ConnectionException) {
            Log::warning('telegram.call', ['method' => $method, 'status' => 'connection_error']);
            $this->reportToAdmin('connection_error', $method, $payload);

            throw new TelegramException('failed');
        }

        Log::info('telegram.call', ['method' => $method, 'status' => $response->status(), 'ms' => (int) ((microtime(true) - $started) * 1000)]);

        if ($response->successful() && $response->json('ok') === true) {
            return $response->json('result');
        }

        $description = mb_strtolower((string) $response->json('description'));

        $exception = match (true) {
            // A group became a supergroup: Telegram tells the new id (Spec 03 section 23.4).
            $response->json('parameters.migrate_to_chat_id') !== null => new TelegramException('migrated', 0, (int) $response->json('parameters.migrate_to_chat_id')),
            $response->status() === 400 && str_contains($description, 'not enough rights') => new TelegramException('no_rights'),
            $response->status() === 400 && str_contains($description, 'thread not found') => new TelegramException('topic_not_found'),
            $response->status() === 403 => new TelegramException('bot_blocked'),
            $response->status() === 400 && str_contains($description, 'chat not found') => new TelegramException('chat_not_found'),
            $response->status() === 429 => new TelegramException('rate_limited', (int) $response->json('parameters.retry_after', 1)),
            $response->status() === 401 => new TelegramException('unauthorized'),
            default => new TelegramException('failed'),
        };

        $this->reportToAdmin($exception->reason, $method, $payload, $response->status());

        throw $exception;
    }

    /**
     * Forward an unexpected bot error to the administrator's Telegram id. Transient or expected
     * outcomes (rate limit, supergroup migration) and users who blocked the bot are not reported.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function reportToAdmin(string $reason, string $method, array $payload, ?int $status = null): void
    {
        $chatId = isset($payload['chat_id']) ? (int) $payload['chat_id'] : 0;

        if (in_array($reason, ['rate_limited', 'migrated'], true) || ($reason === 'bot_blocked' && $chatId > 0)) {
            return;
        }

        app(TelegramAdminAlerts::class)->report($reason, $method.($status !== null ? ' (HTTP '.$status.')' : '').($chatId !== 0 ? ' chat '.$chatId : ''), $method.'|'.$chatId);
    }

    /**
     * Whether the bot receives every message in groups (privacy mode off), from getMe; cached for an
     * hour. Null when Telegram cannot be asked.
     */
    public function canReadAllGroupMessages(): ?bool
    {
        return Cache::remember('telegram.can_read_all_group_messages', now()->addHour(), function (): ?bool {
            try {
                $me = $this->getMe();
            } catch (TelegramException) {
                return null;
            }

            return isset($me['can_read_all_group_messages']) ? (bool) $me['can_read_all_group_messages'] : null;
        });
    }

    protected function token(): ?string
    {
        $stored = Setting::get('telegram.bot_token');

        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }
}
