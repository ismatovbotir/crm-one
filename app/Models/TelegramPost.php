<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log of one internal post (Spec 03 section 23.4): event, destination chat/topic, status
 * (queued|sent|failed|skipped), attempts and error. Only the SHA-256 of the rendered message is
 * kept, never the text. `event_key` is unique per destination (dedupe).
 */
#[Fillable(['telegram_destination_id', 'event', 'event_key', 'subject_type', 'subject_id', 'chat_id', 'message_thread_id', 'status', 'attempts', 'parts', 'message_hash', 'error', 'sent_at'])]
class TelegramPost extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['chat_id' => 'integer', 'message_thread_id' => 'integer', 'attempts' => 'integer', 'parts' => 'integer', 'sent_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<TelegramDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(TelegramDestination::class, 'telegram_destination_id');
    }
}
