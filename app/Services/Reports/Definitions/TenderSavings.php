<?php

namespace App\Services\Reports\Definitions;

use App\Enums\AwardStatus;
use App\Models\Tender;
use App\Models\TenderAward;
use App\Models\User;
use App\Services\Reports\Report;

/**
 * Tender savings: awarded tenders (award approval date in the period) with budget, awarded landed
 * cost and savings. Savings appear only when budget and evaluation use the same currency (no
 * conversion); otherwise the cells stay empty.
 */
class TenderSavings extends Report
{
    public function key(): string
    {
        return 'tender_savings';
    }

    public function allowed(User $user): bool
    {
        return $user->can('viewAny', Tender::class);
    }

    public function columns(): array
    {
        return [
            ['key' => 'number', 'type' => 'text'], ['key' => 'title', 'type' => 'text'], ['key' => 'budget', 'type' => 'money'],
            ['key' => 'awarded', 'type' => 'money'], ['key' => 'savings', 'type' => 'money'], ['key' => 'savings_percent', 'type' => 'percent'],
        ];
    }

    public function rows(User $user, array $filters): iterable
    {
        $statuses = [AwardStatus::Approved, AwardStatus::Accepted];
        $tenders = Tender::query()->visibleTo($user)
            ->whereHas('awards', fn ($q) => $q->whereIn('status', $statuses))
            ->with(['awards' => fn ($q) => $q->whereIn('status', $statuses)])
            ->get();

        foreach ($tenders as $tender) {
            $awards = $tender->awards->filter(function (TenderAward $award) use ($filters): bool {
                $date = $award->approved_at?->toDateString();

                return (! $filters['from'] || ($date !== null && $date >= $filters['from'])) && (! $filters['to'] || ($date !== null && $date <= $filters['to']));
            });

            if ($awards->isEmpty()) {
                continue;
            }

            $currency = (string) $awards->first()->evaluation_currency;
            $awarded = (int) $awards->sum('landed_cost_minor');
            $comparable = $tender->budget_minor !== null && $tender->budget_currency === $currency;
            $savings = $comparable ? (int) $tender->budget_minor - $awarded : null;

            yield [
                'number' => (string) $tender->number,
                'title' => (string) $tender->title,
                'budget' => $tender->budget_minor !== null ? ['minor' => (int) $tender->budget_minor, 'currency' => (string) $tender->budget_currency] : null,
                'awarded' => ['minor' => $awarded, 'currency' => $currency],
                'savings' => $savings !== null ? ['minor' => $savings, 'currency' => $currency] : null,
                'savings_percent' => $comparable && (int) $tender->budget_minor > 0 ? round(100 * $savings / (int) $tender->budget_minor, 1) : null,
            ];
        }
    }
}
