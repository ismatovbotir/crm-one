<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Our bank account printed on agreements and proformas (Spec 06 section 7.9).
 */
#[Fillable(['bank_name', 'mfo', 'account_number', 'currency', 'is_default', 'is_active'])]
class CompanyBankAccount extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public static function defaultFor(string $currency): ?self
    {
        return self::query()->where('is_active', true)->where('currency', strtoupper($currency))->orderByDesc('is_default')->orderBy('id')->first();
    }
}
