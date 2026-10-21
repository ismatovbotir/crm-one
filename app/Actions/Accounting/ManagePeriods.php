<?php

namespace App\Actions\Accounting;

use App\Enums\OutboxStatus;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Enums\PeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\FxRate;
use App\Models\OnecOutboxEvent;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingActionException;
use Carbon\CarbonImmutable;

/**
 * Period lock / reopen (Spec 06 BR-AC.13) and manual CBU rates (BR-AC.8). The lock checklist is
 * computed and stored; open items block unless finance confirms them. Reopen needs a reason and a
 * user other than the one who locked it.
 */
class ManagePeriods
{
    /**
     * @return array{pending_approval: int, unconfirmed: int, outbox_open: int, unallocated: int}
     */
    public function checklist(string $period): array
    {
        $start = CarbonImmutable::parse($period.'-01');
        $end = $start->endOfMonth();
        $inPeriod = fn () => Payment::query()->whereBetween('paid_on', [$start->toDateString(), $end->toDateString()]);

        return [
            'pending_approval' => $inPeriod()->where('status', PaymentStatus::PendingApproval)->count(),
            'unconfirmed' => $inPeriod()->where('source', PaymentSource::Crm)->where('status', PaymentStatus::Recorded)->count(),
            'outbox_open' => OnecOutboxEvent::query()->whereIn('status', [OutboxStatus::Pending, OutboxStatus::Delivered, OutboxStatus::BlockedCustomer])->where('occurred_at', '<=', $end->endOfDay())->count(),
            'unallocated' => $inPeriod()->whereIn('status', [PaymentStatus::Recorded, PaymentStatus::Confirmed])->whereColumn('allocated_minor', '<', 'amount_minor')->count(),
        ];
    }

    public function lock(string $period, User $user, bool $confirmOpenItems = false): AccountingPeriod
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new AccountingActionException('period_invalid');
        }

        $checklist = $this->checklist($period);

        if (! $confirmOpenItems && array_sum($checklist) > 0) {
            throw new AccountingActionException('period_checklist_open');
        }

        return AccountingPeriod::query()->updateOrCreate(['period' => $period], [
            'status' => PeriodStatus::Locked,
            'locked_by' => $user->id,
            'locked_at' => now(),
            'checklist' => $checklist,
        ]);
    }

    public function reopen(AccountingPeriod $period, User $user, string $reason): AccountingPeriod
    {
        if ($period->status !== PeriodStatus::Locked) {
            throw new AccountingActionException('period_not_locked');
        }

        if ($period->locked_by === $user->id) {
            throw new AccountingActionException('self_approval');
        }

        $period->update(['status' => PeriodStatus::Open, 'reopened_by' => $user->id, 'reopened_at' => now(), 'reopen_reason' => $reason]);

        return $period;
    }

    public function saveRate(string $currency, string $date, string $rate, User $user): FxRate
    {
        if (! is_numeric($rate) || bccomp($rate, '0', 8) <= 0) {
            throw new AccountingActionException('fx_rate_required');
        }

        return FxRate::query()->updateOrCreate(['currency' => strtoupper($currency), 'rate_date' => $date], ['rate' => $rate, 'source' => 'cbu_manual', 'entered_by' => $user->id]);
    }
}
