<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Follow-up task on a lead (Spec 04 section 16): due date, optional reminder (sent once by
 * `leads:remind`), completion with user and time.
 */
#[Fillable(['lead_id', 'assignee_id', 'created_by', 'due_at', 'remind_at', 'reminded_at', 'note', 'completed_at', 'completed_by'])]
class LeadFollowUp extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'remind_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function isOverdue(): bool
    {
        return $this->completed_at === null && $this->due_at->isPast();
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
