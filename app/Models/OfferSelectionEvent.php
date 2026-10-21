<?php

namespace App\Models;

use App\Enums\SelectionChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit of customer choices (Spec 05 7.6): never updated or deleted by the app.
 *
 * @property SelectionChannel|null $channel
 */
#[Fillable([
    'offer_id', 'option_group_id', 'offer_line_id', 'event', 'actor', 'user_id', 'contact_id', 'channel', 'note',
    'evidence_note', 'selection_revision', 'created_at',
])]
class OfferSelectionEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Selection events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Selection events are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => SelectionChannel::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<OfferLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(OfferLine::class, 'offer_line_id');
    }
}
