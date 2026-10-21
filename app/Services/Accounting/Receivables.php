<?php

namespace App\Services\Accounting;

use App\Authorization\PermissionMap;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

/**
 * Receivables aging (Spec 06 BR-AC.16): outstanding per schedule item after FIFO coverage,
 * bucketed by days past due (Tashkent), per customer and currency — never summed across
 * currencies; unallocated payments shown as negative advances.
 */
class Receivables
{
    public const BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    public function __construct(protected OrderPayments $orderPayments) {}

    /**
     * @return list<array{customer_id: int, customer: string, currency: string, buckets: array<string, int>, total: int, advance: int}>
     */
    public function aging(User $user): array
    {
        $rows = [];
        $orders = PermissionMap::applyScope(Order::query(), $user, 'receivables.view')
            ->with('customer')
            ->whereNotIn('status', [OrderStatus::New, OrderStatus::Cancelled, OrderStatus::Closed])
            ->get();

        foreach ($orders as $order) {
            foreach ($this->orderPayments->coverage($order) as $row) {
                if ($row['outstanding'] <= 0) {
                    continue;
                }

                $key = $order->customer_id.'|'.$order->currency;
                $rows[$key] ??= $this->row($order->customer_id, (string) $order->customer?->name, $order->currency);
                $bucket = $this->bucket($row['days_overdue']);
                $rows[$key]['buckets'][$bucket] += $row['outstanding'];
                $rows[$key]['total'] += $row['outstanding'];
            }
        }

        $customerIds = $orders->pluck('customer_id')->unique()->all();
        $advances = Payment::query()
            ->where('kind', PaymentKind::Receipt)
            ->whereIn('status', [PaymentStatus::Recorded, PaymentStatus::Confirmed])
            ->whereNotNull('customer_id')
            ->when(PermissionMap::scopeFor($user, 'receivables.view')?->value !== 'all', fn ($query) => $query->whereIn('customer_id', $customerIds))
            ->whereRaw('allocated_minor + refunded_minor < amount_minor')
            ->with('customer')
            ->get();

        foreach ($advances as $payment) {
            $key = $payment->customer_id.'|'.$payment->currency;
            $rows[$key] ??= $this->row((int) $payment->customer_id, (string) $payment->customer?->name, $payment->currency);
            $rows[$key]['advance'] += $payment->unallocatedMinor();
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => [$b['buckets']['d90_plus'] + $b['buckets']['d61_90'], $b['total']] <=> [$a['buckets']['d90_plus'] + $a['buckets']['d61_90'], $a['total']]);

        return $rows;
    }

    /**
     * Totals per currency (each currency separately).
     *
     * @param  list<array{customer_id: int, customer: string, currency: string, buckets: array<string, int>, total: int, advance: int}>  $rows
     * @return array<string, array{buckets: array<string, int>, total: int, advance: int}>
     */
    public function totals(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $totals[$row['currency']] ??= ['buckets' => array_fill_keys(self::BUCKETS, 0), 'total' => 0, 'advance' => 0];

            foreach (self::BUCKETS as $bucket) {
                $totals[$row['currency']]['buckets'][$bucket] += $row['buckets'][$bucket];
            }

            $totals[$row['currency']]['total'] += $row['total'];
            $totals[$row['currency']]['advance'] += $row['advance'];
        }

        ksort($totals);

        return $totals;
    }

    public function bucket(?int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue === null || $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'd1_30',
            $daysOverdue <= 60 => 'd31_60',
            $daysOverdue <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    /**
     * @return array{customer_id: int, customer: string, currency: string, buckets: array<string, int>, total: int, advance: int}
     */
    protected function row(int $customerId, string $name, string $currency): array
    {
        return ['customer_id' => $customerId, 'customer' => $name, 'currency' => $currency, 'buckets' => array_fill_keys(self::BUCKETS, 0), 'total' => 0, 'advance' => 0];
    }
}
