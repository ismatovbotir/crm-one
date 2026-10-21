<?php

namespace App\Services\Accounting;

use App\Enums\ScheduleItemKind;
use App\Models\Order;
use App\Models\PaymentScheduleItem;
use App\Models\User;
use App\Support\BusinessSettings;

/**
 * Payment schedule at confirmation (Spec 06 BR-AC.2): prepayment = round_half_up(gross ×
 * prepayment_bp / 10000), due confirmation + `prepayment_due_days`; the balance is one
 * on-delivery item due `promised_delivery_on + payment_terms_days` (undated until a delivery date
 * is known; delivery notes split it per delivery in job 5). Σ items = order gross. Earlier items
 * are superseded, never deleted.
 */
class PaymentScheduleGenerator
{
    public function generate(Order $order, ?User $user): void
    {
        $now = now();
        $order->scheduleItems()->update(['superseded_at' => $now, 'updated_at' => $now]);

        $gross = $order->gross_minor;
        $prepaymentBp = (int) ($order->prepayment_bp ?? 0);
        $prepayment = $prepaymentBp > 0 ? min($gross, intdiv($gross * $prepaymentBp + 5000, 10000)) : 0;
        $today = AccountingPeriods::today();
        $position = 1;

        if ($prepayment > 0) {
            PaymentScheduleItem::query()->create([
                'order_id' => $order->id,
                'kind' => ScheduleItemKind::Prepayment,
                'percent_bp' => $prepaymentBp,
                'amount_minor' => $prepayment,
                'currency' => $order->currency,
                'due_on' => $today->addDays(BusinessSettings::int('accounting.prepayment_due_days'))->toDateString(),
                'position' => $position++,
                'created_by' => $user?->id,
            ]);
        }

        $balance = $gross - $prepayment;

        if ($balance > 0) {
            $due = $order->promised_delivery_on?->addDays((int) ($order->payment_terms_days ?? 0));

            PaymentScheduleItem::query()->create([
                'order_id' => $order->id,
                'kind' => ScheduleItemKind::OnDelivery,
                'percent_bp' => $prepaymentBp > 0 ? 10000 - $prepaymentBp : null,
                'amount_minor' => $balance,
                'currency' => $order->currency,
                'due_on' => $due?->toDateString(),
                'position' => $position,
                'created_by' => $user?->id,
            ]);
        }
    }
}
