<?php

namespace App\Services\Orders;

use App\Actions\Orders\StartPackaging;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\StockDocumentStatus;
use App\Models\Delivery;
use App\Models\Order;

/**
 * The target sales flow of one order as a stepper (Spec 06 section 25): order → agreement (B2B, or
 * retail when one was added) → payment (retail: full payment + sales receipt; B2B: payment rule +
 * proforma) → packaging (serial numbers) → delivery → power of attorney (B2B, when required) →
 * tax invoice (B2B) → closed. Read-only: it
 * explains where the order is and what has to happen next; the actions enforce the gates.
 */
class OrderFlow
{
    public const DONE = 'done';

    public const CURRENT = 'current';

    public const BLOCKED = 'blocked';

    public const UPCOMING = 'upcoming';

    public function __construct(protected StartPackaging $packaging) {}

    /**
     * @return list<array{key: string, state: string}>
     */
    public function steps(Order $order): array
    {
        $status = $order->status;
        $retail = $order->sale_type->isRetail();
        $agreementOk = $this->packaging->agreementSatisfied($order);
        $paymentOk = $this->packaging->paymentSatisfied($order);

        $steps = [['key' => 'order', 'state' => $status === OrderStatus::New ? self::CURRENT : self::DONE]];

        if ($order->needsAgreementStep()) {
            $steps[] = ['key' => 'agreement', 'state' => match (true) {
                $order->hasBindingAgreement() => self::DONE,
                $status === OrderStatus::New => self::UPCOMING,
                ! $agreementOk => self::BLOCKED,
                default => self::CURRENT,
            }];
        }

        // Retail: full payment and the sales receipt. B2B: the payment rule for packaging is met
        // (prepayment covered, or credit terms); the balance is collected before closing.
        $steps[] = ['key' => $retail ? 'payment_retail' : 'payment_b2b', 'state' => match (true) {
            $status === OrderStatus::New => self::UPCOMING,
            $retail && $order->payment_status === OrderPaymentStatus::Paid && $order->hasIssuedReceipt() => self::DONE,
            $status === OrderStatus::Confirmed && ! $paymentOk => self::BLOCKED,
            $retail => self::CURRENT,
            default => self::DONE,
        }];

        $steps[] = ['key' => 'packaging', 'state' => match (true) {
            in_array($status, [OrderStatus::Packed, OrderStatus::Delivered, OrderStatus::Invoiced, OrderStatus::Closed], true) => self::DONE,
            $status === OrderStatus::Packing => self::CURRENT,
            $status === OrderStatus::PartiallyDelivered => self::CURRENT,
            default => self::UPCOMING,
        }];

        $steps[] = ['key' => $order->isPickup() ? 'pickup' : 'delivery', 'state' => match (true) {
            in_array($status, [OrderStatus::Delivered, OrderStatus::Invoiced, OrderStatus::Closed], true) => self::DONE,
            in_array($status, [OrderStatus::Packed, OrderStatus::PartiallyDelivered], true) => self::CURRENT,
            default => self::UPCOMING,
        }];

        // Spec 06 section 28: power of attorney received before the tax invoice (B2B, when required).
        if (! $retail && $order->poa_required) {
            $steps[] = ['key' => 'power_of_attorney', 'state' => match (true) {
                $order->poaSatisfied() => self::DONE,
                in_array($status, [OrderStatus::Packed, OrderStatus::PartiallyDelivered, OrderStatus::Delivered], true) => self::CURRENT,
                default => self::UPCOMING,
            }];
        }

        if (! $retail) {
            $steps[] = ['key' => 'invoice', 'state' => match (true) {
                in_array($status, [OrderStatus::Invoiced, OrderStatus::Closed], true) => self::DONE,
                $status === OrderStatus::Delivered && ! $order->poaSatisfied() => self::BLOCKED,
                $status === OrderStatus::Delivered => self::CURRENT,
                default => self::UPCOMING,
            }];
        }

        $steps[] = ['key' => 'closed', 'state' => match (true) {
            $status === OrderStatus::Closed => self::DONE,
            $status === OrderStatus::Invoiced || ($retail && $status === OrderStatus::Delivered) => self::CURRENT,
            default => self::UPCOMING,
        }];

        if ($status === OrderStatus::Cancelled) {
            return array_map(fn (array $step): array => [...$step, 'state' => $step['state'] === self::DONE ? self::DONE : self::UPCOMING], $steps);
        }

        return $steps;
    }

    /**
     * Next action as a translation key under orders.flow.next.* (null when nothing is left).
     */
    public function next(Order $order): ?string
    {
        $retail = $order->sale_type->isRetail();

        return match ($order->status) {
            OrderStatus::New => 'confirm',
            OrderStatus::Confirmed => match (true) {
                ! $this->packaging->agreementSatisfied($order) => 'agreement',
                ! $this->packaging->paymentSatisfied($order) => $retail ? 'pay_full' : 'pay_prepayment',
                default => 'start_packaging',
            },
            OrderStatus::Packing => $this->openPackingList($order) === null ? 'create_packing_list' : 'complete_packaging',
            OrderStatus::Packed => $order->isPickup() ? 'hand_over' : 'post_delivery',
            OrderStatus::PartiallyDelivered => 'pack_rest',
            OrderStatus::Delivered => match (true) {
                ! $retail && ! $order->poaSatisfied() => 'power_of_attorney',
                ! $retail => 'tax_invoice',
                $order->payment_status !== OrderPaymentStatus::Paid => 'collect_payment',
                ! $order->hasIssuedReceipt() => 'issue_receipt',
                default => 'close',
            },
            OrderStatus::Invoiced => $order->payment_status === OrderPaymentStatus::Paid ? 'close' : 'collect_payment',
            OrderStatus::Closed, OrderStatus::Cancelled => null,
        };
    }

    public function openPackingList(Order $order): ?Delivery
    {
        return Delivery::query()->where('order_id', $order->id)->where('status', StockDocumentStatus::Draft)->latest('id')->first();
    }
}
