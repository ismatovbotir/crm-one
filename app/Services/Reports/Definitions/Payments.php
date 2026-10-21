<?php

namespace App\Services\Reports\Definitions;

use App\Authorization\PermissionMap;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reports\Report;

/**
 * Payments in the period (by payment date): number, date, customer, payer, method, kind, status,
 * amount and allocated amount.
 */
class Payments extends Report
{
    public function key(): string
    {
        return 'payments';
    }

    public function allowed(User $user): bool
    {
        return PermissionMap::allows($user, 'payments.view_sensitive');
    }

    public function columns(): array
    {
        return [
            ['key' => 'number', 'type' => 'text'], ['key' => 'paid_on', 'type' => 'date'], ['key' => 'customer', 'type' => 'text'], ['key' => 'payer', 'type' => 'text'],
            ['key' => 'method', 'type' => 'text'], ['key' => 'kind', 'type' => 'text'], ['key' => 'status', 'type' => 'text'], ['key' => 'amount', 'type' => 'money'], ['key' => 'allocated', 'type' => 'money'],
        ];
    }

    public function rows(User $user, array $filters): iterable
    {
        $payments = Payment::query()->visibleTo($user)->with('customer:id,name')
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('paid_on', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('paid_on', '<=', $to))
            ->orderBy('paid_on')->orderBy('id')->lazy(500);

        foreach ($payments as $payment) {
            yield [
                'number' => $payment->number,
                'paid_on' => $payment->paid_on?->toDateString(),
                'customer' => (string) $payment->customer?->name,
                'payer' => (string) $payment->payer_name,
                'method' => (string) $payment->method?->label(),
                'kind' => (string) $payment->kind?->label(),
                'status' => (string) $payment->status?->label(),
                'amount' => ['minor' => (int) $payment->amount_minor, 'currency' => $payment->currency],
                'allocated' => ['minor' => (int) $payment->allocated_minor, 'currency' => $payment->currency],
            ];
        }
    }
}
