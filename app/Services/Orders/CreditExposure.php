<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Partner;

/**
 * Customer credit exposure (Spec 06 BR-AC.19): Σ (gross − paid) of the customer's confirmed,
 * unfinished orders in the limit currency. Orders in other currencies are not converted (no FX
 * in the CRM until job 4); they are reported separately so the screen can show them.
 */
class CreditExposure
{
    /**
     * @return array{exposure: int, currency: string|null, limit: int|null, other_currencies: list<string>}
     */
    public function for(Partner $customer, ?Order $except = null): array
    {
        $currency = $customer->credit_limit_currency;
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [OrderStatus::New, OrderStatus::Closed, OrderStatus::Cancelled])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->get(['id', 'currency', 'gross_minor', 'paid_minor']);

        $exposure = 0;
        $others = [];

        foreach ($orders as $order) {
            if ($currency === null || $order->currency === $currency) {
                $exposure += max(0, $order->gross_minor - $order->paid_minor);
            } else {
                $others[] = $order->currency;
            }
        }

        return [
            'exposure' => $exposure,
            'currency' => $currency,
            'limit' => $customer->credit_limit_minor,
            'other_currencies' => array_values(array_unique($others)),
        ];
    }

    /** Whether confirming the order would exceed the customer's limit (null limit = no limit). */
    public function wouldExceed(Order $order): bool
    {
        $customer = $order->customer;

        if ($customer === null || $customer->credit_limit_minor === null || $customer->credit_limit_currency !== $order->currency) {
            return false;
        }

        $current = $this->for($customer, $order);

        return $customer->credit_limit_minor < $current['exposure'] + $order->gross_minor;
    }
}
