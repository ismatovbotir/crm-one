<?php

namespace App\Actions\OrderAmendments;

use App\Actions\Agreements\GenerateAgreement;
use App\Actions\Orders\CreateManualOrder;
use App\Actions\Orders\TransitionOrder;
use App\Enums\AgreementKind;
use App\Enums\HoldReason;
use App\Enums\ItemType;
use App\Enums\OrderAmendmentType;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Models\Agreement;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\Accounting\OrderPayments;
use App\Services\Accounting\PaymentScheduleGenerator;
use App\Services\Agreements\Exceptions\AgreementActionException;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\Orders\CreditExposure;
use App\Services\Orders\Exceptions\OrderAmendmentException;
use App\Services\Orders\OrderAmendmentRules;
use App\Services\Orders\OrderHolds;
use App\Services\Orders\StockAvailability;
use App\Support\Quantity;
use Illuminate\Support\Collection;

/**
 * Applies an approved order amendment (Spec 06 section 6.4, BR-OR.7), inside the caller's
 * transaction: every change is checked again against the order as it is now, the line amounts are
 * recomputed with the offer line math on `quantity − cancelled_qty`, reservations follow the open
 * quantity, the unpaid payment schedule is regenerated, the credit hold is re-evaluated, the order
 * `revision` goes up and `order.amended` becomes available for 1C to pull. A binding agreement whose
 * price or total changed gets an addendum draft and `addendum_pending` (blocks closing, not delivery).
 * A line removed by an amendment keeps its row with the whole quantity cancelled.
 */
class ApplyOrderAmendment
{
    public function __construct(
        protected OrderAmendmentRules $rules,
        protected StockAvailability $stock,
        protected PaymentScheduleGenerator $schedule,
        protected OrderPayments $payments,
        protected CreditExposure $credit,
        protected OrderHolds $holds,
        protected TransitionOrder $transition,
        protected OneCOutbox $outbox,
    ) {}

    public function handle(OrderAmendment $amendment, User $user): void
    {
        $order = Order::query()->lockForUpdate()->findOrFail($amendment->order_id);
        $order->lines()->lockForUpdate()->get();
        $before = $this->totals($order);
        $touched = [];
        $priceChanged = false;

        foreach ($amendment->changes as $stored) {
            $change = $this->rules->normalize($order, $stored);
            $type = OrderAmendmentType::from($change['type']);

            if ($type === OrderAmendmentType::AddLine) {
                $touched[] = $this->addLine($order, $change, $amendment)->id;
                $priceChanged = true;

                continue;
            }

            $line = OrderLine::query()->lockForUpdate()->findOrFail($change['order_line_id']);

            match ($type) {
                OrderAmendmentType::QtyChange => $line->forceFill(['quantity' => Quantity::toStorage((string) $change['quantity'])]),
                OrderAmendmentType::PriceChange => $line->forceFill(['discount_bp' => (int) $change['discount_bp']]),
                OrderAmendmentType::RemoveLine => $line->forceFill(['cancelled_qty' => (string) $line->quantity]),
                OrderAmendmentType::CloseShort => $line->forceFill(['cancelled_qty' => bcadd((string) $line->cancelled_qty, Quantity::toStorage((string) $change['quantity']), Quantity::SCALE)]),
            };
            $line->forceFill($this->rules->amounts($order, $line))->save();
            $touched[] = $line->id;
            $priceChanged = $priceChanged || $type === OrderAmendmentType::PriceChange;
        }

        $lines = $order->lines()->get();
        $order->forceFill([
            'net_minor' => (int) $lines->sum('line_net_minor'),
            'vat_minor' => (int) $lines->sum('line_vat_minor'),
            'gross_minor' => (int) $lines->sum('line_gross_minor'),
        ]);
        $this->assertTotals($order);

        foreach ($lines->whereIn('id', $touched) as $line) {
            $this->syncReservation($order, $line);
        }

        $revisionFrom = (int) $order->revision;
        $order->forceFill(['revision' => $revisionFrom + 1])->save();

        $this->rescheduleAndHold($order, $user);
        $this->completeIfDelivered($order, $user, $lines);

        $totalsAfter = $this->totals($order->fresh());
        $addendum = $this->addendum($order, $user, $priceChanged || $totalsAfter['gross_minor'] !== $before['gross_minor']);
        $amendment->forceFill([
            'revision_from' => $revisionFrom,
            'revision_to' => $revisionFrom + 1,
            'totals_before' => $before,
            'totals_after' => $totalsAfter,
            'addendum_agreement_id' => $addendum?->id,
            'applied_at' => now(),
        ])->save();

        $order->logActivity('amended', ['amendment' => $amendment->number, 'revision' => $revisionFrom + 1, 'gross_before' => $before['gross_minor'], 'gross_after' => $totalsAfter['gross_minor']]);
        $this->outbox->publishOrder('order.amended', $order->fresh());
    }

    /**
     * @param  array<string, mixed>  $change
     */
    protected function addLine(Order $order, array $change, OrderAmendment $amendment): OrderLine
    {
        $row = app(CreateManualOrder::class)->lines([['item_id' => $change['item_id'], 'quantity' => (string) $change['quantity'], 'discount_bp' => (int) $change['discount_bp']]], $order->currency, (bool) $order->prices_include_vat, 10000)[0];
        $line = new OrderLine($row);
        $line->forceFill([
            'order_id' => $order->id,
            'position' => (int) $order->lines()->max('position') + 1,
            'warehouse_id' => $order->warehouse_id,
            'added_by_amendment_id' => $amendment->id,
        ])->save();

        return $line;
    }

    /** Reservations follow the open quantity: reduced when it shrank, topped up from free stock when it grew. */
    protected function syncReservation(Order $order, OrderLine $line): void
    {
        $line->loadMissing('item');

        if ($line->item?->type === ItemType::Service) {
            return;
        }

        $open = bcsub((string) $line->quantity, bcadd((string) $line->delivered_qty, (string) $line->cancelled_qty, Quantity::SCALE), Quantity::SCALE);
        $open = bccomp($open, '0', Quantity::SCALE) < 0 ? '0' : $open;
        $reservations = $line->reservations()->whereIn('status', [ReservationStatus::Active, ReservationStatus::MirroredIn1c])->orderByDesc('id')->lockForUpdate()->get();
        $held = (string) $reservations->sum('quantity');

        if (bccomp($held, $open, Quantity::SCALE) > 0) {
            $excess = bcsub($held, $open, Quantity::SCALE);

            foreach ($reservations as $reservation) {
                if (bccomp($excess, '0', Quantity::SCALE) <= 0) {
                    break;
                }

                $take = bccomp((string) $reservation->quantity, $excess, Quantity::SCALE) <= 0 ? (string) $reservation->quantity : $excess;
                $left = bcsub((string) $reservation->quantity, $take, Quantity::SCALE);
                $reservation->forceFill($this->shrunk($left))->save();
                $excess = bcsub($excess, $take, Quantity::SCALE);
            }

            $held = $open;
        } elseif (bccomp($held, $open, Quantity::SCALE) < 0) {
            $available = $this->stock->forItem($line->item_id, $line->warehouse_id, [$line->id]);
            $need = bcsub($open, $held, Quantity::SCALE);
            $reserve = bccomp($available, $need, Quantity::SCALE) < 0 ? $available : $need;

            if (bccomp($reserve, '0', Quantity::SCALE) > 0) {
                $line->reservations()->create(['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'quantity' => $reserve, 'status' => ReservationStatus::Active]);
                $held = bcadd($held, $reserve, Quantity::SCALE);
            }
        }

        $line->forceFill(['backorder' => bccomp($held, $open, Quantity::SCALE) < 0])->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function shrunk(string $left): array
    {
        return bccomp($left, '0', Quantity::SCALE) > 0
            ? ['quantity' => $left]
            : ['status' => ReservationStatus::Released, 'released_at' => now(), 'release_reason' => 'amended'];
    }

    /** The total never drops below what was paid or already invoiced. */
    protected function assertTotals(Order $order): void
    {
        if ($order->gross_minor < (int) $order->paid_minor) {
            throw new OrderAmendmentException('total_below_paid');
        }

        if ($order->gross_minor < (int) $order->invoiced_gross_minor) {
            throw new OrderAmendmentException('total_below_invoiced');
        }
    }

    protected function rescheduleAndHold(Order $order, User $user): void
    {
        $this->schedule->generate($order, $user);
        $this->payments->recompute($order);

        if ($this->credit->wouldExceed($order)) {
            $this->holds->set($order, HoldReason::CreditLimit);
        } else {
            $this->holds->clear($order, HoldReason::CreditLimit);
        }

        if ($order->isDirty(['hold_reasons', 'on_hold'])) {
            $order->save();
        }
    }

    /**
     * Closing short the last open quantity of a partly delivered order finishes its delivery.
     *
     * @param  Collection<int, OrderLine>  $lines
     */
    protected function completeIfDelivered(Order $order, User $user, $lines): void
    {
        if ($order->status === OrderStatus::PartiallyDelivered && $order->lines()->get()->every(fn (OrderLine $line): bool => $line->isFullyDelivered())) {
            $this->transition->handle($order, OrderStatus::Delivered, $user, 'amended', ['delivered_at' => now()]);
        }
    }

    /**
     * A binding agreement whose price or total changed gets an addendum draft; the order stays open for closing until it is settled.
     */
    protected function addendum(Order $order, User $user, bool $changed): ?Agreement
    {
        if (! $changed || ! $order->agreement_required || ! $order->hasBindingAgreement()) {
            return null;
        }

        $parent = $order->currentAgreement();
        $parent = $parent !== null && $parent->status->isBinding() ? $parent : $order->frameworkAgreement;

        if ($parent === null || ! $parent->status->isBinding()) {
            return null;
        }

        try {
            $addendum = app(GenerateAgreement::class)->handle(AgreementKind::Addendum, $order->customer, null, $user, [
                'parent' => $parent, 'locale' => $parent->document_locale, 'secondary_locale' => $parent->secondary_locale,
            ]);
        } catch (AgreementActionException) {
            // No template or parent: the amendment screen tells the rep to add the addendum by hand.
            return null;
        }

        $order->forceFill(['addendum_pending' => true])->save();

        return $addendum;
    }

    /**
     * @return array{net_minor: int, vat_minor: int, gross_minor: int}
     */
    protected function totals(Order $order): array
    {
        return ['net_minor' => (int) $order->net_minor, 'vat_minor' => (int) $order->vat_minor, 'gross_minor' => (int) $order->gross_minor];
    }
}
