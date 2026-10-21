<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Reports\Definitions\OffersWinRate;
use App\Services\Reports\Definitions\OrdersBacklog;
use App\Services\Reports\Definitions\Payments;
use App\Services\Reports\Definitions\ReceivablesAging;
use App\Services\Reports\Definitions\SalesFunnel;
use App\Services\Reports\Definitions\StockSerials;
use App\Services\Reports\Definitions\TenderSavings;

/**
 * The reports in display order (roadmap step 3).
 */
class ReportRegistry
{
    /** @var list<class-string<Report>> */
    public const REPORTS = [SalesFunnel::class, OffersWinRate::class, OrdersBacklog::class, ReceivablesAging::class, Payments::class, StockSerials::class, TenderSavings::class];

    /**
     * @return list<Report>
     */
    public function allowedFor(User $user): array
    {
        return array_values(array_filter(array_map(fn (string $class): Report => app($class), self::REPORTS), fn (Report $report): bool => $report->allowed($user)));
    }

    public function find(string $key): ?Report
    {
        foreach (self::REPORTS as $class) {
            $report = app($class);

            if ($report->key() === $key) {
                return $report;
            }
        }

        return null;
    }
}
