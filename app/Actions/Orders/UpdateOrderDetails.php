<?php

namespace App\Actions\Orders;

use App\Models\Contact;
use App\Models\Order;
use App\Services\Orders\Exceptions\OrderActionException;

/**
 * Delivery address, dates and contacts of an order still `new` (Spec 06 4.2: no amendment
 * needed before confirmation). Amounts, lines and terms never change here.
 */
class UpdateOrderDetails
{
    /**
     * @param  array{contact_id?: int|null, billing_contact_id?: int|null, delivery_address?: string|null, requested_delivery_on?: string|null, promised_delivery_on?: string|null}  $data
     */
    public function handle(Order $order, array $data): Order
    {
        $order = $order->fresh();

        if (! $order->isBeforeFulfilment()) {
            throw new OrderActionException('details_locked');
        }

        foreach (['contact_id', 'billing_contact_id'] as $key) {
            if (! empty($data[$key]) && ! Contact::query()->whereKey($data[$key])->where('partner_id', $order->customer_id)->exists()) {
                throw new OrderActionException('contact_foreign');
            }
        }

        $order->fill(array_intersect_key($data, array_flip(['contact_id', 'billing_contact_id', 'delivery_address', 'requested_delivery_on', 'promised_delivery_on'])))->save();

        return $order;
    }
}
