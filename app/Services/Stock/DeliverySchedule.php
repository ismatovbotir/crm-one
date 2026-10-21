<?php

namespace App\Services\Stock;

use App\Enums\ScheduleItemKind;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\PaymentScheduleItem;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Offers\Calculation\CalculatorLine;
use App\Services\Offers\OfferCalculator;

/**
 * On-delivery schedule items (Spec 06 BR-AC.2): when a delivery note is posted, an item for the
 * delivered gross × (1 − prepayment share) is created, due `delivered_on + payment_terms_days`; the
 * open balance item shrinks by the same amount; the delivery that completes the order takes the
 * whole remaining balance so Σ items = order gross.
 */
class DeliverySchedule
{
    public function __construct(protected OfferCalculator $calculator) {}

    public function onPosted(Order $order, Delivery $delivery, bool $completesOrder): void
    {
        $balance = PaymentScheduleItem::query()->where('order_id', $order->id)->whereNull('superseded_at')
            ->where('kind', ScheduleItemKind::OnDelivery)->whereNull('delivery_id')->orderBy('position')->first();

        if ($balance === null) {
            return;
        }

        $gross = 0;

        foreach ($delivery->lines()->with('orderLine')->get() as $line) {
            if (bccomp((string) $line->quantity, '0', 3) <= 0) {
                continue;
            }

            $orderLine = $line->orderLine;
            $gross += $this->calculator->line(new CalculatorLine($orderLine->id, (string) $line->quantity, $orderLine->unit_price_minor, $orderLine->discount_bp, $orderLine->tax_rate_bp), $order->prices_include_vat)->gross;
        }

        $share = 10000 - (int) ($order->prepayment_bp ?? 0);
        $amount = $completesOrder ? $balance->amount_minor : min($balance->amount_minor, intdiv($gross * $share + 5000, 10000));

        if ($amount <= 0) {
            return;
        }

        $now = now();
        $balance->forceFill(['superseded_at' => $now])->save();
        $due = AccountingPeriods::today()->addDays((int) ($order->payment_terms_days ?? 0))->toDateString();

        PaymentScheduleItem::query()->create([
            'order_id' => $order->id,
            'kind' => ScheduleItemKind::OnDelivery,
            'label' => $delivery->number,
            'delivery_id' => $delivery->id,
            'amount_minor' => $amount,
            'currency' => $order->currency,
            'due_on' => $due,
            'position' => $balance->position,
        ]);

        if ($balance->amount_minor - $amount > 0) {
            PaymentScheduleItem::query()->create([
                'order_id' => $order->id,
                'kind' => ScheduleItemKind::OnDelivery,
                'percent_bp' => $balance->percent_bp,
                'amount_minor' => $balance->amount_minor - $amount,
                'currency' => $order->currency,
                'due_on' => $balance->due_on?->toDateString(),
                'position' => $balance->position + 1,
            ]);
        }
    }
}
