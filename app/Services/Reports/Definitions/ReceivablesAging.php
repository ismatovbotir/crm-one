<?php

namespace App\Services\Reports\Definitions;

use App\Authorization\PermissionMap;
use App\Models\User;
use App\Services\Accounting\Receivables;
use App\Services\Reports\Report;

/**
 * Receivables aging per customer and currency (Spec 06 aging buckets): current, 1–30, 31–60,
 * 61–90, over 90 days overdue, total and unallocated advances. Point in time (no date range).
 */
class ReceivablesAging extends Report
{
    public function __construct(protected Receivables $receivables) {}

    public function key(): string
    {
        return 'receivables_aging';
    }

    public function allowed(User $user): bool
    {
        return PermissionMap::allows($user, 'receivables.view');
    }

    public function usesDateRange(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            ['key' => 'customer', 'type' => 'text'], ['key' => 'current', 'type' => 'money'], ['key' => 'd1_30', 'type' => 'money'], ['key' => 'd31_60', 'type' => 'money'],
            ['key' => 'd61_90', 'type' => 'money'], ['key' => 'd90_plus', 'type' => 'money'], ['key' => 'total', 'type' => 'money'], ['key' => 'advance', 'type' => 'money'],
        ];
    }

    public function rows(User $user, array $filters): iterable
    {
        foreach ($this->receivables->aging($user) as $row) {
            $money = fn (int $minor): array => ['minor' => $minor, 'currency' => $row['currency']];

            yield [
                'customer' => $row['customer'],
                'current' => $money($row['buckets']['current']),
                'd1_30' => $money($row['buckets']['d1_30']),
                'd31_60' => $money($row['buckets']['d31_60']),
                'd61_90' => $money($row['buckets']['d61_90']),
                'd90_plus' => $money($row['buckets']['d90_plus']),
                'total' => $money($row['total']),
                'advance' => $money($row['advance']),
            ];
        }
    }
}
