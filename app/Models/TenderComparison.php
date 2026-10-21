<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Frozen comparison report (Spec 08 DEC-6, 9.1): the normalised numbers, FX, weights, flags,
 * sensitivity, evidence index and audit data a decision is taken on, with the sha256 of the
 * canonical core. Immutable once submitted — only the status may move to decided/superseded.
 *
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $submitted_at
 */
#[Fillable(['tender_id', 'round_no', 'version', 'status', 'payload', 'payload_hash', 'generated_by', 'generated_at', 'submitted_at', 'superseded_at'])]
class TenderComparison extends Model
{
    use HasUuids;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_DECIDED = 'decided';

    public const STATUS_SUPERSEDED = 'superseded';

    protected static function booted(): void
    {
        static::updating(function (TenderComparison $comparison): void {
            if (array_diff(array_keys($comparison->getDirty()), ['status', 'superseded_at', 'updated_at']) !== []) {
                throw new LogicException('Comparison snapshots are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Comparison snapshots are never deleted.'));
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'round_no' => 'integer',
            'version' => 'integer',
            'generated_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Tender, $this>
     */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
