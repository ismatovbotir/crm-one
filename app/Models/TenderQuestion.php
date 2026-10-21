<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clarification question of a bidder and its answer; answers are published to every invitee
 * without the asker's identity (BR-T.5, AC-22).
 */
#[Fillable(['tender_id', 'counterparty_id', 'asked_by', 'received_via', 'question', 'answer', 'answered_by', 'answered_at', 'published_at'])]
class TenderQuestion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['answered_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Counterparty, $this>
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }
}
