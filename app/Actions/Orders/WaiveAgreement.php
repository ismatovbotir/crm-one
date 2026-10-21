<?php

namespace App\Actions\Orders;

use App\Enums\HoldReason;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Orders\OrderHolds;

/**
 * Waive the agreement requirement for one order (Spec 06 7.2 `agreement_required`: manager or
 * finance, with a reason). Clears the `agreement_unsigned` hold; the change is in the activity log.
 */
class WaiveAgreement
{
    public function __construct(protected OrderHolds $holds) {}

    public function handle(Order $order, User $user, string $reason): Order
    {
        $order = $order->fresh();

        if ($order->status->isFinal()) {
            throw new OrderActionException('order_final');
        }

        if (! $order->agreement_required) {
            return $order;
        }

        $order->forceFill(['agreement_required' => false, 'agreement_waiver_reason' => $reason]);
        $this->holds->clear($order, HoldReason::AgreementUnsigned);
        $order->save();

        return $order;
    }
}
