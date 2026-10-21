<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * A multi-part full snapshot sent by 1C (Spec 02 section 7.4). Rows of the feed that were not seen
 * in any part are deactivated only after the last part, if the safety guard passes.
 *
 * Status: collecting -> finalized | partial (guard tripped) | expired (TTL).
 *
 * @property array{price_type_ids?: list<string>, warehouse_ids?: list<string>}|null $scope
 * @property list<int>|null $parts_processed
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable $started_at
 */
#[Fillable(['id', 'endpoint', 'scope', 'total_parts', 'parts_processed', 'status', 'result', 'started_at', 'finalized_at'])]
class InboundSnapshot extends Model
{
    use MassPrunable;

    public const COLLECTING = 'collecting';

    public const FINALIZED = 'finalized';

    public const PARTIAL = 'partial';

    public const EXPIRED = 'expired';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'parts_processed' => 'array',
            'result' => 'array',
            'total_parts' => 'integer',
            'started_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    public function isComplete(): bool
    {
        return count(array_unique($this->parts_processed ?? [])) >= $this->total_parts;
    }

    public function isPastTtl(): bool
    {
        return $this->started_at->lt(now()->subMinutes((int) config('onec.inbound.snapshot_ttl_minutes')));
    }

    /**
     * Finished snapshots are pruned after 90 days (DB review 4.6).
     *
     * @return Builder<InboundSnapshot>
     */
    public function prunable(): Builder
    {
        return static::query()->where('status', '!=', self::COLLECTING)->where('finalized_at', '<', now()->subDays(90));
    }
}
