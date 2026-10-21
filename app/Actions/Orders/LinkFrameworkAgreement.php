<?php

namespace App\Actions\Orders;

use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Enums\HoldReason;
use App\Models\Agreement;
use App\Models\Order;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Orders\OrderHolds;

/**
 * Put an order under an active framework agreement of the same customer (Spec 06 BR-AG.3): the
 * agreement requirement is then satisfied and the `agreement_unsigned` hold cleared. Passing null
 * unlinks it.
 */
class LinkFrameworkAgreement
{
    public function __construct(protected OrderHolds $holds) {}

    public function handle(Order $order, ?Agreement $agreement): Order
    {
        $order = $order->fresh();

        if ($order->status->isFinal()) {
            throw new OrderActionException('order_final');
        }

        if ($agreement !== null && ($agreement->kind !== AgreementKind::Framework || $agreement->status !== AgreementStatus::Active || $agreement->customer_id !== $order->customer_id)) {
            throw new OrderActionException('framework_invalid');
        }

        $order->framework_agreement_id = $agreement?->id;

        if ($order->hasBindingAgreement()) {
            $this->holds->clear($order, HoldReason::AgreementUnsigned);
        }

        $order->save();

        return $order;
    }
}
