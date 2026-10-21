<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Exchange rate: UZS per 1 unit of `currency` on `rate_date` (Spec 06 7.14, CBU, entered manually).
 *
 * @property CarbonImmutable $rate_date
 * @property string $rate
 */
#[Fillable(['currency', 'rate_date', 'rate', 'source', 'entered_by'])]
class FxRate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rate_date' => 'immutable_date', 'rate' => 'decimal:8'];
    }
}
