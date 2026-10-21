<?php

namespace App\Services\Telegram\Internal;

use App\Enums\InternalEvent;
use App\Jobs\PostInternalTelegramMessage;
use App\Models\TelegramDestination;
use App\Models\TelegramPost;
use App\Services\Telegram\TelegramClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes an internal event to every active destination that routes it (Spec 03 section 23):
 * renders the message in the destination's language and content level, records one
 * `telegram_posts` row per destination and event key (insert-or-ignore = dedupe) and queues the
 * send on the `telegram` queue after the surrounding transaction commits. Never throws into the
 * business action; outbound traffic goes only through TelegramClient (api.telegram.org).
 */
class InternalNotifications
{
    public const QUEUE = 'telegram';

    public function __construct(protected TelegramClient $client, protected MessageRenderer $renderer) {}

    /**
     * @param  array<string, mixed>  $context
     * @return int posts queued
     */
    public function publish(InternalEvent $event, ?Model $subject, array $context = [], ?string $key = null): int
    {
        try {
            if (! $this->client->enabled()) {
                return 0;
            }

            $key ??= $event->value.':'.($subject !== null ? $subject->getMorphClass().':'.$subject->getKey() : now()->format('YmdHi'));
            $queued = 0;

            foreach (TelegramDestination::query()->where('is_active', true)->orderBy('id')->get() as $destination) {
                $level = $destination->levelFor($event);

                if ($level === null) {
                    continue;
                }

                $html = $this->renderer->render($event, $subject, $context, $level, $destination->include_amounts, $destination->locale);
                $inserted = DB::table('telegram_posts')->insertOrIgnore([
                    'telegram_destination_id' => $destination->id,
                    'event' => $event->value,
                    'event_key' => mb_substr($key, 0, 190),
                    'subject_type' => $subject?->getMorphClass(),
                    'subject_id' => $subject?->getKey(),
                    'chat_id' => $destination->chat_id,
                    'message_thread_id' => $destination->message_thread_id,
                    'status' => 'queued',
                    'attempts' => 0,
                    'parts' => count(MessageRenderer::split($html)),
                    'message_hash' => hash('sha256', $html),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted === 0) {
                    continue; // already posted (or queued) for this destination
                }

                $postId = (int) TelegramPost::query()->where('telegram_destination_id', $destination->id)->where('event_key', mb_substr($key, 0, 190))->value('id');
                PostInternalTelegramMessage::dispatch($postId, $html)->onQueue(self::QUEUE)->afterCommit();
                $queued++;
            }

            return $queued;
        } catch (Throwable $exception) {
            Log::warning('telegram.internal.publish_failed', ['event' => $event->value, 'error' => $exception::class]);

            return 0;
        }
    }

    /** A test message to one destination (Settings > Telegram), sent through the queue as well. */
    public function test(TelegramDestination $destination): void
    {
        $html = '<b>'.e(__('telegram.internal.test_title', [], $destination->locale)).'</b>'."\n".e(__('telegram.internal.test_body', ['name' => $destination->name], $destination->locale));
        $post = TelegramPost::query()->create([
            'telegram_destination_id' => $destination->id,
            'event' => 'test',
            'event_key' => 'test:'.now()->format('YmdHisv'),
            'chat_id' => $destination->chat_id,
            'message_thread_id' => $destination->message_thread_id,
            'status' => 'queued',
            'message_hash' => hash('sha256', $html),
        ]);

        PostInternalTelegramMessage::dispatch($post->id, $html)->onQueue(self::QUEUE);
    }
}
