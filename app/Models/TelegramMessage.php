<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal append-only log of messages the bot received (Spec 04 section 17): ids only, no text
 * (the text is kept on the lead activity). Unique per chat, message, kind and edit.
 */
#[Fillable(['telegram_chat_id', 'telegram_contact_id', 'message_id', 'kind', 'edit_date', 'lead_id', 'received_at'])]
class TelegramMessage extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['message_id' => 'integer', 'edit_date' => 'integer', 'received_at' => 'immutable_datetime'];
    }
}
