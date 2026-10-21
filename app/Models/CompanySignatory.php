<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Person who signs for the company (Spec 06 section 7.9, Q-7: the CEO/director by default;
 * others with power of attorney number/date and validity).
 *
 * @property CarbonImmutable|null $valid_until
 */
#[Fillable(['full_name', 'position', 'basis', 'valid_until', 'is_default', 'is_active'])]
class CompanySignatory extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['valid_until' => 'immutable_date', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function isValidOn(CarbonInterface $date): bool
    {
        return $this->is_active && ($this->valid_until === null || $this->valid_until->toDateString() >= $date->toDateString());
    }

    public static function default(): ?self
    {
        return self::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
    }
}
