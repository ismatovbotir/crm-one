<?php

namespace App\Services\Reports\Definitions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Reports\Report;

/**
 * Orders backlog: every open order (not closed or cancelled) with status, sale type, amount, paid,
 * promised delivery and days since creation, oldest first. The date range filters the order date.
 */
class OrdersBacklog extends Report
{
    public function key(): string
    {
        return 'orders_backlog';
    }

    public function allowed(User $user): bool
    {
        return $user->can('viewAny', Order::class);
    }

    public function columns(): array
    {
        return [
            ['key' => 'number', 'type' => 'text'], ['key' => 'customer', 'type' => 'text'], ['key' => 'status', 'type' => 'text'], ['key' => 'sale_type', 'type' => 'text'],
            ['key' => 'total', 'type' => 'money'], ['key' => 'paid', 'type' => 'money'], ['key' => 'promised', 'type' => 'date'], ['key' => 'age_days', 'type' => 'int'],
        ];
    }

    public function rows(User $user, array $filters): iterable
    {
        $orders = Order::query()->visibleTo($user)->with('customer:id,name')
            ->whereNotIn('status', [OrderStatus::Closed, OrderStatus::Cancelled])
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderBy('created_at')->orderBy('id')->lazy(500);

        foreach ($orders as $order) {
            yield [
                'number' => $order->number,
                'customer' => (string) $order->customer?->name,
                'status' => $order->status->label(),
                'sale_type' => $order->sale_type->label(),
                'total' => ['minor' => $order->gross_minor, 'currency' => $order->currency],
                'paid' => ['minor' => $order->paid_minor, 'currency' => $order->currency],
                'promised' => $order->promised_delivery_on?->toDateString(),
                'age_days' => (int) $order->created_at->diffInDays(now()),
            ];
        }
    }
}
