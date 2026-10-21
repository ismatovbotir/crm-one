<?php

namespace App\Jobs;

use App\Models\TelegramDestination;
use App\Models\TelegramPost;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\Internal\InternalAlerts;
use App\Services\Telegram\Internal\MessageRenderer;
use App\Services\Telegram\TelegramChats;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Sends one internal post (Spec 03 section 23.4) on the `telegram` queue: splits long messages,
 * respects the per-chat limit (20 messages a minute) and Telegram's retry_after, follows a
 * supergroup migration, retries transient failures with backoff and records the outcome on the
 * post (never the text). Permanent failures (bot removed, no rights, topic gone) mark the
 * destination and alert the admins once.
 */
class PostInternalTelegramMessage implements ShouldQueue
{
    use Queueable;

    public const PER_CHAT_PER_MINUTE = 20;

    public const PERMANENT = ['bot_blocked', 'chat_not_found', 'unauthorized', 'no_rights', 'topic_not_found', 'disabled'];

    public int $tries = 6;

    public function __construct(public int $postId, public string $html) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600, 1800];
    }

    public function handle(TelegramClient $client): void
    {
        $post = TelegramPost::query()->with('destination')->find($this->postId);

        if ($post === null || $post->status === 'sent') {
            return;
        }

        $key = 'tg-post|'.$post->chat_id;

        if (RateLimiter::tooManyAttempts($key, self::PER_CHAT_PER_MINUTE)) {
            $this->release(max(1, RateLimiter::availableIn($key)));

            return;
        }

        $post->forceFill(['attempts' => $post->attempts + 1])->save();
        $parts = MessageRenderer::split($this->html);
        $progressKey = 'tg-post-progress:'.$post->id;
        $done = (int) Cache::get($progressKey, 0);

        try {
            foreach ($parts as $index => $part) {
                if ($index < $done) {
                    continue;
                }

                RateLimiter::hit($key, 60);
                $client->sendMessage($post->chat_id, $part, null, $post->message_thread_id);
                Cache::put($progressKey, $index + 1, now()->addDay());
            }
        } catch (TelegramException $exception) {
            $this->failedWith($post, $exception);

            return;
        }

        Cache::forget($progressKey);
        $post->forceFill(['status' => 'sent', 'error' => null, 'sent_at' => now(), 'parts' => count($parts)])->save();
        $post->destination?->forceFill(['last_success_at' => now(), 'last_error' => null, 'last_error_at' => null])->saveQuietly();
    }

    protected function failedWith(TelegramPost $post, TelegramException $exception): void
    {
        if ($exception->reason === 'rate_limited') {
            $this->release(max(1, $exception->retryAfter));

            return;
        }

        if ($exception->reason === 'migrated' && $exception->migrateToChatId !== null) {
            app(TelegramChats::class)->migrate($post->chat_id, $exception->migrateToChatId);
            TelegramDestination::query()->where('chat_id', $post->chat_id)->update(['chat_id' => $exception->migrateToChatId]);
            TelegramPost::query()->where('chat_id', $post->chat_id)->whereIn('status', ['queued', 'retrying'])->update(['chat_id' => $exception->migrateToChatId]);
            $this->release(1);

            return;
        }

        $post->forceFill(['error' => $exception->reason])->save();

        if (in_array($exception->reason, self::PERMANENT, true)) {
            $post->forceFill(['status' => 'failed'])->save();

            if ($post->destination !== null) {
                app(InternalAlerts::class)->destinationFailed($post->destination, $exception->reason);
            }

            return;
        }

        $post->forceFill(['status' => 'retrying'])->save();

        throw $exception;
    }

    public function failed(?Throwable $exception): void
    {
        TelegramPost::query()->whereKey($this->postId)->where('status', '!=', 'sent')->update(['status' => 'failed', 'error' => $exception instanceof TelegramException ? $exception->reason : 'failed']);
        $post = TelegramPost::query()->with('destination')->find($this->postId);
        $post?->destination?->forceFill(['last_error' => $post->error, 'last_error_at' => now()])->saveQuietly();
    }
}
