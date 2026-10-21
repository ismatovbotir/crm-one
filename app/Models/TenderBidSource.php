<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence of an employee-entered bid (BR-T.14, Spec 08 BR-B.2): channel, time received
 * (≤ now and ≤ deadline), sender, note; files are attachments of the bid.
 */
#[Fillable(['bid_id', 'received_via', 'received_at', 'sender_name', 'sender_contact', 'note', 'entered_by', 'created_at'])]
class TenderBidSource extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enterer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
