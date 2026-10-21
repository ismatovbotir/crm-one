<?php

namespace App\Services\Dashboard;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\GoodsReceiptStatus;
use App\Enums\LeadStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceTicketStatus;
use App\Enums\StockDocumentStatus;
use App\Enums\TenderStatus;
use App\Models\DeliveryLine;
use App\Models\GoodsReceipt;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ServiceTicket;
use App\Models\Tender;
use App\Models\TenderComparison;
use App\Models\User;
use App\Services\Equipment\ServiceQueue;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;

/**
 * Role-based dashboard (roadmap step 2): each section appears when the user's permissions cover
 * it, so combined roles see combined dashboards. All figures respect the record visibility of the
 * user; money is summed per base currency only (other currencies are counted, never converted).
 *  - sales: lead funnel, my open leads, follow-ups due, open offers and offers expiring in 7 days;
 *  - accountant / finance: outstanding receivable, unpaid and overdue orders, unallocated payments;
 *  - warehouse: orders waiting for packaging, packing lists with missing serials, draft documents;
 *  - logistics / purchase: open tenders and deadlines in the next 7 days;
 *  - approvers (CEO, heads, managers): tender and discount approvals and comparison reports waiting.
 */
class DashboardMetrics
{
    /**
     * @return list<array{key: string, tiles: list<array{label: string, value: string, hint?: string, url?: string|null}>}>
     */
    public function sections(User $user): array
    {
        $sections = [];

        if ($user->can('viewAny', Lead::class)) {
            $sections[] = ['key' => 'sales', 'tiles' => $this->sales($user)];
        }

        // Accountants, finance and managers (sales see their own orders' payment status on the orders).
        if (Gate::forUser($user)->allows('viewAny', Payment::class) && PermissionMap::allows($user, 'payments.view_sensitive')) {
            $sections[] = ['key' => 'accounting', 'tiles' => $this->accounting($user)];
        }

        if ($user->can('viewPackaging', Order::class)) {
            $sections[] = ['key' => 'warehouse', 'tiles' => $this->warehouse()];
        }

        // Service workload tiles are for the service team and managers (sales read their customers' tickets in the list).
        if ($user->can('viewAny', ServiceTicket::class) && PermissionMap::scopeFor($user, 'service.view') === DataScope::All) {
            $sections[] = ['key' => 'service', 'tiles' => $this->service($user)];
        }

        if ($user->can('viewAny', Tender::class)) {
            $sections[] = ['key' => 'procurement', 'tiles' => $this->procurement($user)];
        }

        $approvals = $this->approvals($user);

        if ($approvals !== []) {
            $sections[] = ['key' => 'approvals', 'tiles' => $approvals];
        }

        return $sections;
    }

    /**
     * @return list<array{label: string, value: string, hint?: string, url?: string|null}>
     */
    protected function sales(User $user): array
    {
        $leads = Lead::query()->visibleTo($user);
        $byStatus = (clone $leads)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $funnel = collect([LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::Converted])
            ->map(fn (LeadStatus $status): string => $status->label().' '.(int) ($byStatus[$status->value] ?? 0))->join(' → ');
        $offers = Offer::query()->visibleTo($user)->whereIn('status', [OfferStatus::Sent, OfferStatus::Viewed, OfferStatus::PartiallySelected]);
        $accepted = Offer::query()->visibleTo($user)->where('status', OfferStatus::Accepted)->where('accepted_at', '>=', now()->subDays(90))->count();
        $decided = Offer::query()->visibleTo($user)->whereIn('status', [OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Expired])->where('updated_at', '>=', now()->subDays(90))->count();

        // Spec 04 section 18.7: lead-to-customer conversion and time to convert, last 90 days.
        $recent = (clone $leads)->where('created_at', '>=', now()->subDays(90))->get(['status', 'created_at', 'converted_at']);
        $recentConverted = $recent->where('status', LeadStatus::Converted)->filter(fn (Lead $lead): bool => $lead->converted_at !== null);
        $avgDays = $recentConverted->isEmpty() ? null : round($recentConverted->avg(fn (Lead $lead): float => max(0, $lead->created_at->diffInHours($lead->converted_at)) / 24), 1);

        return [
            ['label' => __('dashboard.tiles.funnel'), 'value' => $funnel, 'hint' => __('dashboard.tiles.funnel_hint'), 'url' => route('leads.index', ['status' => 'all'])],
            ['label' => __('dashboard.tiles.lead_conversion'), 'value' => $recent->isNotEmpty() ? round(100 * $recentConverted->count() / $recent->count()).' %' : '—', 'hint' => $avgDays !== null ? __('dashboard.tiles.lead_conversion_hint', ['days' => $avgDays]) : __('dashboard.tiles.lead_conversion_none'), 'url' => route('reports.show', 'sales_funnel')],
            ['label' => __('dashboard.tiles.my_open_leads'), 'value' => (string) Lead::query()->where('owner_id', $user->id)->whereIn('status', LeadStatus::openValues())->count(), 'hint' => __('dashboard.tiles.stale', ['count' => Lead::query()->where('owner_id', $user->id)->stale()->count()]), 'url' => route('leads.index', ['owner' => 'mine'])],
            ['label' => __('dashboard.tiles.follow_ups_due'), 'value' => (string) LeadFollowUp::query()->where('assignee_id', $user->id)->whereNull('completed_at')->where('due_at', '<=', now()->endOfDay())->count(), 'url' => route('leads.index', ['owner' => 'mine'])],
            ['label' => __('dashboard.tiles.open_offers'), 'value' => (string) (clone $offers)->count(), 'hint' => __('dashboard.tiles.expiring', ['count' => (clone $offers)->whereDate('valid_until', '<=', now()->addDays(7))->count()]), 'url' => route('offers.index')],
            ['label' => __('dashboard.tiles.win_rate'), 'value' => $decided > 0 ? round(100 * $accepted / $decided).' %' : '—', 'hint' => __('dashboard.tiles.win_rate_hint')],
        ];
    }

    /**
     * @return list<array{label: string, value: string, hint?: string, url?: string|null}>
     */
    protected function accounting(User $user): array
    {
        $base = (string) config('crm.base_currency', 'UZS');
        $active = Order::query()->visibleTo($user)->whereNotIn('status', [OrderStatus::New, OrderStatus::Closed, OrderStatus::Cancelled]);
        $outstanding = (int) (clone $active)->where('currency', $base)->selectRaw('coalesce(sum(gross_minor - paid_minor), 0) as s')->value('s');

        return [
            ['label' => __('dashboard.tiles.receivable'), 'value' => Money::of(max(0, $outstanding), $base)->format(), 'hint' => __('dashboard.tiles.receivable_hint', ['currency' => $base]), 'url' => route('receivables.index')],
            ['label' => __('dashboard.tiles.unpaid_orders'), 'value' => (string) (clone $active)->whereIn('payment_status', [OrderPaymentStatus::Unpaid, OrderPaymentStatus::PartiallyPaid, OrderPaymentStatus::Overdue])->count(), 'url' => route('orders.index')],
            ['label' => __('dashboard.tiles.overdue_orders'), 'value' => (string) (clone $active)->where('payment_status', OrderPaymentStatus::Overdue)->count(), 'url' => route('receivables.index')],
            ['label' => __('dashboard.tiles.unallocated_payments'), 'value' => (string) Payment::query()->where('kind', 'receipt')->whereIn('status', [PaymentStatus::Recorded, PaymentStatus::Confirmed])->whereNotNull('customer_id')->whereRaw('allocated_minor + refunded_minor < amount_minor')->count(), 'url' => route('payments.index', ['queue' => 'unallocated'])],
        ];
    }

    /**
     * Spec 09: open, overdue, waiting for parts, mine.
     *
     * @return list<array{label: string, value: string, hint?: string, url?: string|null}>
     */
    protected function service(User $user): array
    {
        $open = ServiceTicket::query()->whereIn('status', ServiceTicketStatus::openValues());
        $overdue = (clone $open)->where(fn ($w) => $w->where(fn ($r) => $r->where('status', ServiceTicketStatus::New->value)->where('respond_due_at', '<', now()))
            ->orWhere(fn ($r) => $r->whereNull('ready_at')->whereNotNull('repair_due_at')->where('repair_due_at', '<', now())))->count();

        return [
            ['label' => __('dashboard.tiles.service_open'), 'value' => (string) (clone $open)->count(), 'hint' => __('dashboard.tiles.service_new', ['count' => (clone $open)->where('status', ServiceTicketStatus::New->value)->count()]), 'url' => route('service.tickets')],
            ['label' => __('dashboard.tiles.service_overdue'), 'value' => (string) $overdue, 'url' => route('service.tickets', ['queue' => 'overdue'])],
            // Spec 09 section 15: "Waiting in queue (out of stock)" length and oldest wait.
            ['label' => __('dashboard.tiles.service_waiting'), 'value' => (string) ($queue = app(ServiceQueue::class)->summary())['length'], 'hint' => $queue['oldest_days'] !== null ? __('dashboard.tiles.service_queue_oldest', ['days' => $queue['oldest_days']]) : null, 'url' => route('service.tickets', ['queue' => 'waiting'])],
            ['label' => __('dashboard.tiles.service_mine'), 'value' => (string) (clone $open)->where('assigned_to', $user->id)->count(), 'url' => route('service.tickets', ['queue' => 'mine'])],
        ];
    }

    /**
     * @return list<array{label: string, value: string, hint?: string, url?: string|null}>
     */
    protected function warehouse(): array
    {
        $missing = DeliveryLine::query()->where('is_serialized', true)
            ->whereHas('delivery', fn ($q) => $q->where('status', StockDocumentStatus::Draft)->whereNull('packed_at'))
            ->whereHas('orderLine', fn ($q) => $q->whereColumn('order_lines.quantity', '>', 'delivery_lines.quantity'))
            ->count();

        return [
            ['label' => __('dashboard.tiles.to_pack'), 'value' => (string) Order::query()->whereIn('status', [OrderStatus::Confirmed, OrderStatus::PartiallyDelivered])->count(), 'url' => route('packaging.index', ['stage' => 'waiting'])],
            ['label' => __('dashboard.tiles.packing'), 'value' => (string) Order::query()->where('status', OrderStatus::Packing)->count(), 'hint' => __('dashboard.tiles.serials_missing', ['count' => $missing]), 'url' => route('packaging.index', ['stage' => 'packing'])],
            ['label' => __('dashboard.tiles.ready_to_deliver'), 'value' => (string) Order::query()->where('status', OrderStatus::Packed)->count(), 'url' => route('packaging.index', ['stage' => 'packed'])],
            ['label' => __('dashboard.tiles.draft_receipts'), 'value' => (string) GoodsReceipt::query()->whereIn('status', [GoodsReceiptStatus::ReceivedFromOneC, GoodsReceiptStatus::InProgress, GoodsReceiptStatus::Checked, GoodsReceiptStatus::OnHold])->count(), 'url' => route('goods-receipts.index')],
        ];
    }

    /**
     * @return list<array{label: string, value: string, hint?: string, url?: string|null}>
     */
    protected function procurement(User $user): array
    {
        $visible = Tender::query()->visibleTo($user);

        return [
            ['label' => __('dashboard.tiles.open_tenders'), 'value' => (string) (clone $visible)->whereIn('status', [TenderStatus::Published, TenderStatus::Closed, TenderStatus::Evaluation])->count()],
            ['label' => __('dashboard.tiles.deadlines'), 'value' => (string) (clone $visible)->where('status', TenderStatus::Published)->whereBetween('bids_due_at', [now(), now()->addDays(7)])->count(), 'hint' => __('dashboard.tiles.deadlines_hint')],
            ['label' => __('dashboard.tiles.drafts'), 'value' => (string) (clone $visible)->where('status', TenderStatus::Draft)->count()],
        ];
    }

    /**
     * @return list<array{label: string, value: string, hint?: string, url?: string|null}>
     */
    protected function approvals(User $user): array
    {
        $tiles = [];
        $tenderPublications = Tender::query()->where('status', TenderStatus::PendingApproval)->get()->filter(fn (Tender $tender): bool => $user->can('approvePublication', $tender))->count();
        $tenderAwards = Tender::query()->where('status', TenderStatus::AwardPendingApproval)->get()->filter(fn (Tender $tender): bool => $user->can('decideAward', $tender))->count();

        if ($tenderPublications + $tenderAwards > 0 || $user->can('viewAny', Tender::class)) {
            $tiles[] = ['label' => __('dashboard.tiles.tender_approvals'), 'value' => (string) ($tenderPublications + $tenderAwards), 'hint' => __('dashboard.tiles.tender_approvals_hint', ['publish' => $tenderPublications, 'award' => $tenderAwards]), 'url' => route('approvals.index')];
            $tiles[] = ['label' => __('dashboard.tiles.comparisons'), 'value' => (string) TenderComparison::query()->where('status', TenderComparison::STATUS_SUBMITTED)->whereHas('tender', fn ($q) => $q->where('status', TenderStatus::AwardPendingApproval))->count(), 'url' => route('approvals.index')];
        }

        $discounts = Offer::query()->where('status', OfferStatus::PendingApproval)->get()->filter(fn (Offer $offer): bool => $user->can('approve', $offer))->count();

        if ($discounts > 0 || ($user->can('viewAny', Offer::class) && PermissionMap::allows($user, 'offers.approve'))) {
            $tiles[] = ['label' => __('dashboard.tiles.offer_approvals'), 'value' => (string) $discounts, 'url' => route('offers.index', ['status' => 'pending_approval'])];
        }

        return $tiles;
    }
}
