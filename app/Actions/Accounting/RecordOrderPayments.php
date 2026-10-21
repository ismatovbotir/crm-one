<?php

namespace App\Actions\Accounting;

use App\Enums\OrderStatus;
use App\Models\CompanyBankAccount;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\OrderPayments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * "Payment for orders" (Spec 06 section 29, user decision 2026-10-19): the partner, the ticked
 * unpaid orders and an amount per order (default: the outstanding amount). The CRM payment is an
 * information signal for the warehouse (what may be shipped); the books stay in 1C. One payment
 * per order in the order currency, allocated to it at once (never more than outstanding, so no
 * advances). The existing rules stay: open period, duplicate guard, proof above the threshold,
 * second-person approval above the approval threshold (then allocated when approved), immutable
 * records corrected by a void. Available for 1C to pull as `payment.recorded`.
 */
class RecordOrderPayments
{
    public function __construct(protected RecordPayment $record, protected AllocatePayment $allocate, protected OrderPayments $orderPayments) {}

    /**
     * @param  array{customer_id: int, paid_on: string, method: string, reference_number?: string|null, note?: string|null, orders: array<int, int>}  $data  orders: order id => amount (minor, order currency)
     * @return list<Payment>
     */
    public function handle(array $data, User $user, ?UploadedFile $proof = null, bool $confirmDuplicate = false): array
    {
        $customer = Customer::withTrashed()->findOrFail($data['customer_id']);
        $amounts = array_filter($data['orders'], fn (int $amount): bool => $amount > 0);

        if ($amounts === []) {
            throw new AccountingActionException('select_orders');
        }

        return DB::transaction(function () use ($data, $user, $proof, $confirmDuplicate, $customer, $amounts): array {
            $payments = [];

            foreach (Order::query()->whereIn('id', array_keys($amounts))->orderBy('id')->lockForUpdate()->get() as $order) {
                $amount = $amounts[$order->id];

                if ($order->customer_id !== $customer->id || in_array($order->status, [OrderStatus::New, OrderStatus::Cancelled, OrderStatus::Closed], true)) {
                    throw new AccountingActionException('order_not_payable');
                }

                $outstanding = $order->gross_minor - $this->orderPayments->allocatedMinor($order);

                if ($amount > $outstanding) {
                    throw new AccountingActionException('exceeds_order', ['amount' => $order->money(max(0, $outstanding))->format()]);
                }

                $payment = $this->record->handle([
                    'customer_id' => $customer->id,
                    'amount_minor' => $amount,
                    'currency' => $order->currency,
                    'paid_on' => $data['paid_on'],
                    'method' => $data['method'],
                    'company_bank_account_id' => CompanyBankAccount::defaultFor($order->currency)?->id,
                    'payer_name' => (string) ($customer->legal_name ?: $customer->name),
                    'payer_tax_id' => $customer->tax_id,
                    'reference_number' => filled($data['reference_number'] ?? null) ? (string) $data['reference_number'] : null,
                    'purpose' => __('accounting.order_payments.purpose', ['number' => $order->number]),
                    'note' => $data['note'] ?? null,
                ], $user, $proof, $confirmDuplicate);

                $payment->forceFill(['planned_order_id' => $order->id])->save();

                if ($payment->status->isAllocatable()) {
                    $this->allocate->handle($payment, $order, $amount, $user);
                }

                $payments[] = $payment->fresh();
            }

            return $payments;
        });
    }
}
