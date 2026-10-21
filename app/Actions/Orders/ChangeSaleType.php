<?php

namespace App\Actions\Orders;

use App\Enums\HoldReason;
use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Models\Order;
use App\Models\User;
use App\Services\Accounting\OrderPayments;
use App\Services\Accounting\PaymentScheduleGenerator;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Orders\OrderHolds;
use App\Support\BusinessSettings;

/**
 * Override the sale type of an order before packaging starts and before any payment (Spec 06 section 25, DEC-25.2): retail means full
 * payment before packaging (prepayment 100 %, no credit days) and no agreement requirement;
 * switching back to B2B restores the customer's payment terms and the agreement setting.
 * The change appears in the order's activity log.
 */
class ChangeSaleType
{
    public function handle(Order $order, SaleType $saleType, User $user): Order
    {
        $order = $order->fresh();

        // Spec 06 section 31: allowed until packaging starts and before any payment counts.
        if (! $order->isBeforeFulfilment() || $order->paid_minor > 0) {
            throw new OrderActionException('details_locked');
        }

        if ($order->sale_type === $saleType) {
            return $order;
        }

        $retail = $saleType === SaleType::Retail;

        $order->forceFill([
            'sale_type' => $saleType,
            'agreement_required' => ! $retail && BusinessSettings::bool('orders.agreement_required'),
            'prepayment_bp' => $retail ? 10000 : null,
            'payment_terms_days' => $retail ? 0 : $order->customer?->payment_terms_days,
            'poa_required' => Order::poaRequiredByDefault($saleType, $order->customer),
        ])->save();

        // A started order gets the payment schedule and the agreement hold of its new type.
        if ($order->status === OrderStatus::Confirmed) {
            app(PaymentScheduleGenerator::class)->generate($order, $user);
            app(OrderPayments::class)->recompute($order);
            $holds = app(OrderHolds::class);

            if ($order->agreement_required && ! $order->hasBindingAgreement() && BusinessSettings::bool('orders.block_fulfilment_without_agreement')) {
                $holds->set($order, HoldReason::AgreementUnsigned);
            } else {
                $holds->clear($order, HoldReason::AgreementUnsigned);
            }

            $order->save();
        }

        return $order;
    }
}
