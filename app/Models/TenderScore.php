<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Committee member's score 0-10 of a manual criterion for one bid lot (BR-T.9).
 */
#[Fillable(['bid_lot_id', 'criterion_id', 'user_id', 'score', 'comment'])]
class TenderScore extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
