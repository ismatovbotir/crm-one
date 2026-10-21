<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\SyncsWithOneC;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unit of measure mirrored from 1C. Only `precision` (allowed quantity decimals, 0..3) is CRM-local.
 */
#[Fillable(['code', 'name', 'precision', 'is_active', 'external_id', 'external_code', 'synced_at'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory, LogsActivity, SyncsWithOneC;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precision' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['external_hash', 'synced_at', 'last_seen_snapshot_id'];
    }
}
