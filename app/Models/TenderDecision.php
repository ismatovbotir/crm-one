<?php

namespace App\Models;

use App\Enums\ApprovalLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only approval decision (Spec 08 BR-D.3): publishing approvals and award approvals per
 * level (head / CEO / finance), with the decider, comment, IP and user agent. Never updated or
 * deleted; a wrong decision is corrected only by a new tender action.
 *
 * @property ApprovalLevel $level
 */
#[Fillable(['tender_id', 'award_id', 'comparison_id', 'comparison_hash', 'selected', 'subject', 'level', 'decision', 'comment', 'decided_by', 'confirmed_via', 'ip', 'user_agent', 'created_at'])]
class TenderDecision extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Tender decisions are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Tender decisions are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['level' => ApprovalLevel::class, 'selected' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
