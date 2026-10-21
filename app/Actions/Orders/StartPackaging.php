<?php

namespace App\Actions\Orders;

use App\Enums\HoldReason;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Accounting\OrderPayments;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Orders\OrderHolds;
use App\Support\BusinessSettings;
use Illuminate\Support\Facades\DB;

/**
 * confirmed / partially delivered → packing (Spec 06 6.1/6.2, section 25 gate G2, AC-6): refused
 * while a hold applies that was not overridden.
 *  - agreement: `agreement_unsigned` while an agreement is required (B2B by default; retail only
 *    when one was added), none is signed/active and `orders.block_fulfilment_without_agreement`;
 *  - payment rule: B2B needs the prepayment schedule item covered (no prepayment = credit terms,
 *    nothing to wait for); retail needs the order fully paid. Otherwise `prepayment_missing`.
 * Packaging is warehouse work (stock manager, warehouse staff and head).
 */
class StartPackaging
{
    public function __construct(protected TransitionOrder $transition, protected OrderHolds $holds) {}

    public function handle(Order $order, User $user): Order
    {
        $order = $order->fresh();

        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::PartiallyDelivered], true)) {
            throw new OrderActionException('invalid_transition', ['from' => $order->status->label(), 'to' => OrderStatus::Packing->label()]);
        }

        if (! $this->agreementSatisfied($order)) {
            $this->holds->set($order, HoldReason::AgreementUnsigned);
        } else {
            $this->holds->clear($order, HoldReason::AgreementUnsigned);
        }

        if ($this->paymentSatisfied($order)) {
            $this->holds->clear($order, HoldReason::PrepaymentMissing);
        } else {
            $this->holds->set($order, HoldReason::PrepaymentMissing);
        }

        if ($order->isDirty(['hold_reasons', 'on_hold'])) {
            $order->save();
        }

        $active = $order->activeHolds();

        if ($active !== []) {
            throw new OrderActionException('on_hold', ['reasons' => implode(', ', array_map(fn (HoldReason $hold): string => $hold->label(), $active))]);
        }

        return DB::transaction(fn (): Order => $this->transition->handle($order, OrderStatus::Packing, $user, null, $order->started_at === null ? ['started_at' => now()] : []));
    }

    public function agreementSatisfied(Order $order): bool
    {
        return ! ($order->agreement_required && ! $order->hasBindingAgreement() && BusinessSettings::bool('orders.block_fulfilment_without_agreement'));
    }

    /** Payment rule of the order (section 25 G2): retail fully paid, B2B prepayment covered. */
    public function paymentSatisfied(Order $order): bool
    {
        if ($order->sale_type->isRetail()) {
            return $order->payment_status === OrderPaymentStatus::Paid;
        }

        return app(OrderPayments::class)->prepaymentCovered($order);
    }
}
