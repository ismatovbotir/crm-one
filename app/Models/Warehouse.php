<?php

namespace App\Models;

use App\Models\Concerns\SyncsWithOneC;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Warehouse mirrored from 1C (Spec 02 section 11.3).
 */
#[Fillable(['name', 'is_active', 'external_id', 'external_code', 'synced_at'])]
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory, SyncsWithOneC;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<ItemStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(ItemStock::class);
    }
}
