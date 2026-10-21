<?php

namespace App\Services\Reports\Definitions;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\Reports\Report;

/**
 * Sales funnel and conversion by lead source: leads created in the period, how many reached
 * contacted, qualified and converted (offer or customer), closed as lost or disqualified, the
 * conversion rate, how many became customers through "Convert to customer" and the average days
 * from creation to conversion (Spec 04 section 18.7). Only leads the user may see.
 */
class SalesFunnel extends Report
{
    public function key(): string
    {
        return 'sales_funnel';
    }

    public function allowed(User $user): bool
    {
        return $user->can('viewAny', Lead::class);
    }

    public function columns(): array
    {
        return [
            ['key' => 'source', 'type' => 'text'], ['key' => 'leads', 'type' => 'int'], ['key' => 'contacted', 'type' => 'int'],
            ['key' => 'qualified', 'type' => 'int'], ['key' => 'converted', 'type' => 'int'], ['key' => 'closed', 'type' => 'int'], ['key' => 'conversion', 'type' => 'percent'],
            ['key' => 'customers', 'type' => 'int'], ['key' => 'days_to_convert', 'type' => 'int'],
        ];
    }

    public function rows(User $user, array $filters): iterable
    {
        $leads = Lead::query()->visibleTo($user)
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->get(['source', 'status', 'qualified_at', 'converted_at', 'conversion_kind', 'created_at']);

        foreach (LeadSource::cases() as $source) {
            $group = $leads->where('source', $source);

            if ($group->isEmpty()) {
                continue;
            }

            $convertedLeads = $group->where('status', LeadStatus::Converted);
            $converted = $convertedLeads->count();
            $days = $convertedLeads->filter(fn (Lead $lead): bool => $lead->converted_at !== null && $lead->created_at !== null)
                ->map(fn (Lead $lead): float => max(0, $lead->created_at->diffInHours($lead->converted_at)) / 24);

            yield [
                'source' => $source->label(),
                'leads' => $group->count(),
                'contacted' => $group->reject(fn (Lead $lead): bool => $lead->status === LeadStatus::New)->count(),
                'qualified' => $group->filter(fn (Lead $lead): bool => $lead->qualified_at !== null)->count(),
                'converted' => $converted,
                'closed' => $group->filter(fn (Lead $lead): bool => $lead->status->isClosed())->count(),
                'conversion' => round(100 * $converted / $group->count(), 1),
                'customers' => $convertedLeads->whereNotNull('conversion_kind')->count(),
                'days_to_convert' => $days->isEmpty() ? null : round($days->avg(), 1),
            ];
        }
    }
}
