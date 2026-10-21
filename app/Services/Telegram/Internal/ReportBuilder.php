<?php

namespace App\Services\Telegram\Internal;

use App\Enums\LeadStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\RequisitionStatus;
use App\Enums\ServiceTicketStatus;
use App\Models\GoodsReceiptLine;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Requisition;
use App\Models\ServiceTicket;
use App\Models\StatusTransition;
use Carbon\CarbonImmutable;

/**
 * Company-wide figures for the daily and weekly Telegram reports and the read-only bot commands
 * (Spec 03 section 23.2/23.5): counts, plus amounts as {minor, currency} entries that the renderer
 * drops unless the destination includes amounts. Keys starting with `detail_` are shown only at
 * the standard level. No names, contacts or serial numbers.
 */
class ReportBuilder
{
    public const TIMEZONE = 'Asia/Tashkent';

    /**
     * @return array{period: string, sections: array<string, array<string, mixed>>}
     */
    public function build(string $period = 'daily', ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(self::TIMEZONE);
        $from = $period === 'weekly' ? $now->subDays(6)->startOfDay() : $now->startOfDay();
        $fromUtc = $from->utc();
        $base = (string) config('crm.base_currency', 'UZS');

        $label = $period === 'weekly'
            ? $from->format('d.m.Y').' – '.$now->format('d.m.Y')
            : $now->format('d.m.Y');

        return [
            'period' => $label,
            'sections' => [
                'sales' => $this->sales($fromUtc, $base),
                'receivables' => $this->receivables($base),
                'tickets' => $this->tickets(),
                'stock' => $this->stock($fromUtc),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sales(CarbonImmutable $fromUtc, string $base): array
    {
        $confirmedIds = $this->transitioned('order', OrderStatus::Confirmed->value, $fromUtc);

        return [
            'leads_created' => Lead::query()->where('created_at', '>=', $fromUtc)->count(),
            'detail_leads_open' => Lead::query()->whereIn('status', LeadStatus::openValues())->count(),
            'requests_received' => count($this->transitioned('requisition', RequisitionStatus::Received->value, $fromUtc)),
            'offers_sent' => count($this->transitioned('offer', 'sent', $fromUtc)),
            'offers_accepted' => count($this->transitioned('offer', 'accepted', $fromUtc)),
            'orders_created' => Order::query()->where('created_at', '>=', $fromUtc)->count(),
            'orders_confirmed' => count($confirmedIds),
            'orders_delivered' => count($this->transitioned('order', OrderStatus::Delivered->value, $fromUtc)),
            'orders_confirmed_amount' => ['minor' => (int) Order::query()->whereKey($confirmedIds)->where('currency', $base)->sum('gross_minor'), 'currency' => $base],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function receivables(string $base): array
    {
        $active = Order::query()->whereNotIn('status', [OrderStatus::New, OrderStatus::Closed, OrderStatus::Cancelled]);

        return [
            'overdue_orders' => (clone $active)->where('payment_status', OrderPaymentStatus::Overdue)->count(),
            'detail_unpaid_orders' => (clone $active)->whereIn('payment_status', [OrderPaymentStatus::Unpaid, OrderPaymentStatus::PartiallyPaid, OrderPaymentStatus::Overdue])->count(),
            'outstanding_amount' => ['minor' => max(0, (int) (clone $active)->where('currency', $base)->selectRaw('coalesce(sum(gross_minor - paid_minor), 0) as s')->value('s')), 'currency' => $base],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tickets(): array
    {
        $open = ServiceTicket::query()->whereIn('status', ServiceTicketStatus::openValues());

        return [
            'tickets_open' => (clone $open)->count(),
            'tickets_overdue' => (clone $open)->where(fn ($w) => $w->where(fn ($r) => $r->where('status', ServiceTicketStatus::New->value)->where('respond_due_at', '<', now()))
                ->orWhere(fn ($r) => $r->whereNull('ready_at')->whereNotNull('repair_due_at')->where('repair_due_at', '<', now())))->count(),
            'detail_tickets_waiting' => ServiceTicket::query()->where('status', ServiceTicketStatus::WaitingForParts->value)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stock(CarbonImmutable $fromUtc): array
    {
        return [
            'receipt_discrepancies' => GoodsReceiptLine::query()->whereNotNull('discrepancy_reason')
                ->whereHas('receipt', fn ($q) => $q->where('status', 'posted')->where('updated_at', '>=', $fromUtc))->count(),
        ];
    }

    /** Open customer requests on our side (received or quoting). */
    public function openRequests(): int
    {
        return Requisition::query()->whereIn('status', [RequisitionStatus::Received->value, RequisitionStatus::Quoting->value])->count();
    }

    /**
     * Subject ids that reached the status since the moment.
     *
     * @return list<int>
     */
    protected function transitioned(string $subjectType, string $status, CarbonImmutable $fromUtc): array
    {
        return StatusTransition::query()->where('subject_type', $subjectType)->where('to_status', $status)->where('created_at', '>=', $fromUtc)
            ->distinct()->pluck('subject_id')->map(fn ($id): int => (int) $id)->all();
    }
}
