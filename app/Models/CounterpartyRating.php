<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rating of a supplier/carrier (quality, communication, documents 1-5, on time). The
 * counterparty rating is the 12-month rolling average (RateCounterparty).
 */
#[Fillable(['counterparty_id', 'subject_type', 'subject_id', 'on_time', 'quality', 'communication', 'documents', 'comment', 'rated_by', 'created_at'])]
class CounterpartyRating extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_time' => 'boolean',
            'quality' => 'integer',
            'communication' => 'integer',
            'documents' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_by');
    }
}
