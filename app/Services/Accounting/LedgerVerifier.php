<?php

namespace App\Services\Accounting;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\Log;

/**
 * Nightly integrity check (Spec 06 BR-AC.15): recomputes the cached balances from the append-only
 * allocation rows and reports differences (no auto-fix); also refreshes order payment statuses so
 * items that became overdue overnight show (BR-AC.18).
 */
class LedgerVerifier
{
    public function __construct(protected OrderPayments $orderPayments) {}

    /**
     * @return array{payments_checked: int, orders_checked: int, differences: list<string>, statuses_updated: int}
     */
    public function run(): array
    {
        $differences = [];
        $paymentsChecked = 0;
        $ordersChecked = 0;
        $updated = 0;

        Payment::query()->select(['id', 'number', 'allocated_minor'])->chunkById(500, function ($payments) use (&$differences, &$paymentsChecked): void {
            $sums = PaymentAllocation::query()->whereIn('payment_id', $payments->modelKeys())->groupBy('payment_id')->selectRaw('payment_id, sum(amount_minor) as total')->pluck('total', 'payment_id');

            foreach ($payments as $payment) {
                $paymentsChecked++;
                $expected = max(0, (int) ($sums[$payment->id] ?? 0));

                if ($expected !== $payment->allocated_minor) {
                    $differences[] = "payment {$payment->number}: allocated {$payment->allocated_minor} != {$expected}";
                }
            }
        });

        Order::query()->whereNotIn('status', [OrderStatus::New, OrderStatus::Cancelled])->chunkById(200, function ($orders) use (&$differences, &$ordersChecked, &$updated): void {
            foreach ($orders as $order) {
                $ordersChecked++;
                $expected = $this->orderPayments->paidMinor($order);

                if ($expected !== $order->paid_minor) {
                    $differences[] = "order {$order->number}: paid {$order->paid_minor} != {$expected}";
                }

                $status = $order->payment_status;
                $this->orderPayments->recompute($order);
                $updated += $status !== $order->payment_status ? 1 : 0;
            }
        });

        if ($differences !== []) {
            Log::warning('accounting.verify differences', ['count' => count($differences), 'first' => array_slice($differences, 0, 20)]);
        }

        return ['payments_checked' => $paymentsChecked, 'orders_checked' => $ordersChecked, 'differences' => $differences, 'statuses_updated' => $updated];
    }
}
