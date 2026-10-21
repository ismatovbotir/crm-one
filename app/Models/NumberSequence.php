<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Yearly sequential numbers per entity key, never reused (Spec 05 BR-25).
 */
#[Fillable(['key', 'year', 'last_value'])]
class NumberSequence extends Model
{
    /**
     * Next value for the key and year, under a row lock (call inside the creating transaction).
     */
    public static function next(string $key, int $year): int
    {
        // DB review F-12: insert-or-ignore first, then lock the existing row (no gap lock on a
        // missing row, so two first numbers of a year cannot deadlock).
        return DB::transaction(function () use ($key, $year): int {
            DB::table('number_sequences')->insertOrIgnore(['key' => $key, 'year' => $year, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = self::query()->where('key', $key)->where('year', $year)->lockForUpdate()->firstOrFail();
            $row->increment('last_value');

            return (int) $row->last_value;
        });
    }

    /**
     * Formatted number, e.g. format('OF', 2026, 42) => "OF-2026-00042".
     */
    public static function format(string $prefix, int $year, int $value): string
    {
        return sprintf('%s-%d-%05d', $prefix, $year, $value);
    }
}
