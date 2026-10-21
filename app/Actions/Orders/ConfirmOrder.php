<?php

namespace App\Actions\Orders;

use App\Enums\HoldReason;
use App\Enums\ItemType;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\Accounting\OrderPayments;
use App\Services\Accounting\PaymentScheduleGenerator;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\Orders\CreditExposure;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Orders\OrderHolds;
use App\Services\Orders\StockAvailability;
use App\Support\BusinessSettings;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * new → confirmed (Spec 06 6.1, BR-OR.3/5/6/7, BR-AC.19):
 *  - the customer must not be deleted in 1C or archived;
 *  - manual and credit-limit holds block unless overridden (the hold is stored and the
 *    confirmation refused, so the reason is visible on the order);
 *  - stock is checked per goods line against StockAvailability; shortages need the
 *    acknowledgement (or are refused when `orders.block_confirm_on_shortage`); short lines are
 *    flagged backorder and reservations are created for the available quantity only;
 *  - the `agreement_unsigned` hold is set when an agreement is required and none is binding.
 * The payment schedule is generated and `order.confirmed` is written to the 1C outbox.
 */
class ConfirmOrder
{
    public function __construct(
        protected TransitionOrder $transition,
        protected StockAvailability $stock,
        protected CreditExposure $credit,
        protected OrderHolds $holds,
        protected PaymentScheduleGenerator $schedule,
        protected OneCOutbox $outbox,
    ) {}

    public function handle(Order $order, User $user, bool $acknowledgeShortages = false): Order
    {
        $order = $this->checkHolds($order);

        return DB::transaction(function () use ($order, $user, $acknowledgeShortages): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            // Spec 06 section 31: orders start on creation; starting again is a no-op.
            if ($order->status === OrderStatus::Confirmed) {
                return $order;
            }

            if ($order->status !== OrderStatus::New) {
                throw new OrderActionException('invalid_transition', ['from' => $order->status->label(), 'to' => OrderStatus::Confirmed->label()]);
            }

            $shortages = $this->shortages($order);

            if ($shortages !== []) {
                if (BusinessSettings::bool('orders.block_confirm_on_shortage')) {
                    throw new OrderActionException('shortage_blocked', ['lines' => $this->describe($shortages)]);
                }

                if (! $acknowledgeShortages) {
                    throw new OrderActionException('shortage_unacknowledged', ['lines' => $this->describe($shortages)]);
                }
            }

            foreach ($order->lines as $line) {
                $short = $shortages[$line->id] ?? null;
                $line->backorder = $short !== null;
                $line->save();

                if (! $this->isGoods($line)) {
                    continue;
                }

                $reserve = $short === null ? (string) $line->quantity : $short['available'];

                if (bccomp($reserve, '0', Quantity::SCALE) > 0) {
                    $line->reservations()->create([
                        'item_id' => $line->item_id,
                        'warehouse_id' => $line->warehouse_id,
                        'quantity' => $reserve,
                        'status' => ReservationStatus::Active,
                    ]);
                }
            }

            if ($order->agreement_required && ! $order->hasBindingAgreement() && BusinessSettings::bool('orders.block_fulfilment_without_agreement')) {
                $this->holds->set($order, HoldReason::AgreementUnsigned);
            }

            $this->transition->handle($order, OrderStatus::Confirmed, $user, $shortages !== [] ? 'shortage_acknowledged' : null, [
                'confirmed_at' => now(),
                'confirmed_by' => $user->id,
                'shortage_acknowledged' => $shortages !== [],
            ]);

            // BR-AC.2 schedule; DEC-14 outbox event in the same transaction (held until the customer reaches 1C).
            $this->schedule->generate($order, $user);
            app(OrderPayments::class)->recompute($order);
            $this->outbox->publishOrder('order.confirmed', $order->fresh());

            return $order;
        });
    }

    /**
     * Shortage per goods line: available (after earlier lines of the same item) < quantity.
     *
     * @return array<int, array{name: string, quantity: string, available: string}>
     */
    public function shortages(Order $order): array
    {
        $remaining = [];
        $shortages = [];

        foreach ($order->lines()->with('item')->get() as $line) {
            if (! $this->isGoods($line)) {
                continue;
            }

            $key = $line->item_id.'@'.($line->warehouse_id ?? '*');
            $remaining[$key] ??= $this->stock->forItem($line->item_id, $line->warehouse_id, $order->lines->pluck('id')->all());
            $available = $remaining[$key];

            if (bccomp($available, (string) $line->quantity, Quantity::SCALE) < 0) {
                $shortages[$line->id] = ['name' => $line->name, 'quantity' => (string) $line->quantity, 'available' => $available];
                $remaining[$key] = '0';
            } else {
                $remaining[$key] = bcsub($available, (string) $line->quantity, Quantity::SCALE);
            }
        }

        return $shortages;
    }

    /**
     * Refuses (after storing the hold, outside the confirmation transaction) when the customer is
     * blocked or a manual / credit hold applies and has not been overridden.
     */
    protected function checkHolds(Order $order): Order
    {
        $order = $order->fresh(['customer']);
        $customer = $order->customer;

        if ($customer === null || $customer->trashed() || $customer->isDeletedInOneC()) {
            throw new OrderActionException('customer_blocked');
        }

        $blocking = [];

        if ($customer->manual_hold && $this->holds->set($order, HoldReason::Manual)) {
            $blocking[] = HoldReason::Manual;
        } elseif (! $customer->manual_hold) {
            $this->holds->clear($order, HoldReason::Manual);
        }

        // 6.2: items overdue beyond `accounting.hold_overdue_days` on other orders hold new ones.
        if (app(OrderPayments::class)->customerOverdueBeyond($customer, BusinessSettings::int('accounting.hold_overdue_days'), $order)) {
            if ($this->holds->set($order, HoldReason::Overdue)) {
                $blocking[] = HoldReason::Overdue;
            }
        } else {
            $this->holds->clear($order, HoldReason::Overdue);
        }

        if ($this->credit->wouldExceed($order)) {
            if ($this->holds->set($order, HoldReason::CreditLimit)) {
                $blocking[] = HoldReason::CreditLimit;
            }
        } else {
            $this->holds->clear($order, HoldReason::CreditLimit);
        }

        if ($order->isDirty(['hold_reasons', 'on_hold'])) {
            $order->save();
        }

        if ($blocking !== []) {
            throw new OrderActionException('on_hold', ['reasons' => implode(', ', array_map(fn (HoldReason $hold): string => $hold->label(), $blocking))]);
        }

        return $order;
    }

    protected function isGoods(OrderLine $line): bool
    {
        return $line->item?->type !== ItemType::Service;
    }

    /**
     * @param  array<int, array{name: string, quantity: string, available: string}>  $shortages
     */
    protected function describe(array $shortages): string
    {
        return implode('; ', array_map(
            fn (array $short): string => $short['name'].' ('.Quantity::trimmed($short['available']).' / '.Quantity::trimmed($short['quantity']).')',
            $shortages,
        ));
    }
}
