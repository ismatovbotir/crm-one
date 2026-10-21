<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Discount approval request bound to the offer content hash (Spec 05 7.7, DEC-6).
 *
 * @property ApprovalStatus $status
 * @property list<array<string, mixed>>|null $reasons
 */
#[Fillable([
    'offer_id', 'required_bp', 'owner_limit_bp', 'reasons', 'status', 'requested_by', 'requested_at', 'decided_by',
    'decided_at', 'comment', 'content_hash',
])]
class OfferApproval extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'reasons' => 'array',
            'required_bp' => 'integer',
            'owner_limit_bp' => 'integer',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
