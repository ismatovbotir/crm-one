<?php

namespace App\Services\Reports;

use App\Models\User;

/**
 * A report (roadmap step 3): who may run it, its columns and its rows for a user and filters.
 * Rows carry raw values; money cells are ['minor' => int, 'currency' => string] so the screen
 * formats them and exports write major units with the currency in its own column.
 */
abstract class Report
{
    /** Stable key used in URLs and translations (reports.list.<key>). */
    abstract public function key(): string;

    abstract public function allowed(User $user): bool;

    /**
     * @return list<array{key: string, type: 'text'|'int'|'money'|'percent'|'date'}>
     */
    abstract public function columns(): array;

    /**
     * @param  array{from: string|null, to: string|null}  $filters
     * @return iterable<array<string, mixed>>
     */
    abstract public function rows(User $user, array $filters): iterable;

    /** Whether the date range filter applies. */
    public function usesDateRange(): bool
    {
        return true;
    }
}
