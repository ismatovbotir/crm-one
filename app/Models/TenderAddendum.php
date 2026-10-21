<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Change to a published tender (BR-T.3): version + 1, optional new deadline (extended to
 * now + 48 h when less remains). Append-only.
 */
#[Fillable(['tender_id', 'version', 'summary', 'changes', 'previous_bids_due_at', 'new_bids_due_at', 'created_by', 'created_at'])]
class TenderAddendum extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'tender_addenda';

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Addenda are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Addenda are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'previous_bids_due_at' => 'immutable_datetime',
            'new_bids_due_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
