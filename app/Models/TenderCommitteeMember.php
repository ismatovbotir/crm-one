<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evaluation committee member (BR-T.9): declares no conflict of interest before seeing bids and
 * scores manual criteria independently.
 */
#[Fillable(['tender_id', 'user_id', 'role', 'coi_declared_at'])]
class TenderCommitteeMember extends Model
{
    protected $table = 'tender_committee';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['coi_declared_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
