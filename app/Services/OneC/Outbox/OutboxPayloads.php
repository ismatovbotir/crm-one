<?php

namespace App\Services\OneC\Outbox;

use App\Enums\ReceiptSource;
use App\Models\Agreement;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\ServiceTicket;
use App\Models\StockAdjustment;
use App\Models\StockOffAct;
use App\Services\Offers\Calculation\CalculatorLine;
use App\Services\Offers\OfferCalculator;
use App\Support\BusinessSettings;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonInterface;

/**
 * Full current snapshots of CRM documents for 1C (Spec 06 9.2): decimal strings, ISO dates with
 * offset, lowercase GUIDs; lines carry the 1C item GUID and the accounting codes; customers only
 * their CRM id and 1C GUID. No internal notes, owner names or customer bank details.
 */
class OutboxPayloads
{
    /**
     * @return array<string, mixed>
     */
    public function order(Order $order): array
    {
        $order->loadMissing(['lines', 'customer']);
        $m = fn (int $minor): string => Money::of($minor, $order->currency)->toMajor();

        return [
            'number' => $order->number,
            'date' => $this->date($order->confirmed_at ?? $order->created_at),
            'currency' => $order->currency,
            'prices_include_vat' => $order->prices_include_vat,
            'status' => $order->status->value,
            'customer' => $this->customer($order->customer),
            'power_of_attorney' => $this->powerOfAttorney($order),
            'source' => $order->source->value,
            'delivery_method' => $order->delivery_method->value,
            'payment_terms' => [
                'prepayment_percent' => $order->prepayment_bp !== null ? bcdiv((string) $order->prepayment_bp, '100', 2) : null,
                'deferred_days' => $order->payment_terms_days,
            ],
            'delivery' => ['address' => $order->delivery_address, 'promised_on' => $order->promised_delivery_on?->toDateString()],
            'lines' => $order->lines->map(fn (OrderLine $line): array => [
                'crm_line_id' => (string) $line->id,
                'item_id' => $line->item_external_id !== null ? strtolower($line->item_external_id) : null,
                'sku' => $line->sku,
                'name' => $line->name,
                'accountant_code' => $line->accountant_code,
                'class_code' => $line->class_code,
                'package_code' => $line->package_code,
                'unit_code' => $line->unit_code,
                'quantity' => (string) $line->quantity,
                'cancelled_quantity' => (string) $line->cancelled_qty,
                'unit_price' => $m($line->unit_price_minor),
                'discount_percent' => bcdiv((string) $line->discount_bp, '100', 2),
                'vat_rate' => rtrim(rtrim(bcdiv((string) $line->tax_rate_bp, '100', 2), '0'), '.'),
                'vat_exempt' => $line->is_vat_exempt,
                'net' => $m($line->line_net_minor),
                'vat' => $m($line->line_vat_minor),
                'total' => $m($line->line_gross_minor),
            ])->values()->all(),
            'totals' => ['net' => $m($order->net_minor), 'vat' => $m($order->vat_minor), 'total' => $m($order->gross_minor)],
            'cancel_reason' => $order->cancel_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'order', 'customer']);
        $m = fn (?int $minor): ?string => $minor === null ? null : Money::of($minor, $invoice->currency)->toMajor();

        return [
            'type' => $invoice->type->value,
            'status' => $invoice->status->value,
            'number' => $invoice->number,
            'date' => $invoice->issued_on->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'order' => ['crm_id' => $invoice->order->uuid, 'number' => $invoice->order->number, 'external_id' => $invoice->order->onec_guid, 'power_of_attorney' => $this->powerOfAttorney($invoice->order)],
            'customer' => $this->customer($invoice->customer),
            'currency' => $invoice->currency,
            'esf_id' => $invoice->esf_id,
            'requested_amount' => $m($invoice->requested_amount_minor),
            'lines' => $invoice->lines->map(fn ($line): array => [
                'crm_order_line_id' => (string) $line->order_line_id,
                'item_id' => $line->item_external_id !== null ? strtolower($line->item_external_id) : null,
                'accountant_code' => $line->accountant_code,
                'class_code' => $line->class_code,
                'package_code' => $line->package_code,
                'quantity' => (string) $line->quantity,
                'unit_price' => $m($line->unit_price_minor),
                'vat_rate' => rtrim(rtrim(bcdiv((string) $line->tax_rate_bp, '100', 2), '0'), '.'),
                'net' => $m($line->line_net_minor),
                'vat' => $m($line->line_vat_minor),
                'total' => $m($line->line_gross_minor),
            ])->values()->all(),
            'totals' => ['net' => $m($invoice->net_minor), 'vat' => $m($invoice->vat_minor), 'total' => $m($invoice->gross_minor)],
            'void_reason' => $invoice->void_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(Payment $payment): array
    {
        $payment->loadMissing(['customer', 'bankAccount', 'allocations.order', 'refundOf']);

        return [
            'number' => $payment->number,
            'direction' => $payment->direction,
            'kind' => $payment->kind->value,
            'status' => $payment->status->value,
            'customer' => $this->customer($payment->customer),
            'amount' => $payment->money()->toMajor(),
            'currency' => $payment->currency,
            'paid_on' => $payment->paid_on->toDateString(),
            'method' => $payment->method->value,
            'bank_account_id' => $payment->bankAccount?->onec_guid,
            'payer_name' => $payment->payer_name,
            'payer_tax_id' => $payment->payer_tax_id,
            'reference_number' => $payment->reference_number,
            'purpose' => $payment->purpose,
            'refund_of' => $payment->refundOf !== null ? ['crm_id' => $payment->refundOf->uuid, 'external_id' => $payment->refundOf->onec_guid] : null,
            'allocations' => $payment->allocations->groupBy('order_id')->map(fn ($rows) => [
                'order_crm_id' => $rows->first()->order->uuid,
                'order_number' => $rows->first()->order->number,
                'amount' => Money::of((int) $rows->sum('amount_minor'), $payment->currency)->toMajor(),
            ])->filter(fn (array $row): bool => bccomp($row['amount'], '0', 2) > 0)->values()->all(),
            'void_reason' => $payment->void_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function agreement(Agreement $agreement): array
    {
        $agreement->loadMissing(['order', 'customer']);

        return [
            'number' => $agreement->number,
            'kind' => $agreement->kind->value,
            'status' => $agreement->status->value,
            'customer' => $this->customer($agreement->customer),
            'order' => $agreement->order !== null ? ['crm_id' => $agreement->order->uuid, 'number' => $agreement->order->number] : null,
            'signed_on' => $agreement->signed_on?->toDateString(),
            'starts_on' => $agreement->starts_on?->toDateString(),
            'ends_on' => $agreement->ends_on?->toDateString(),
            'terminated_on' => $agreement->terminated_on?->toDateString(),
            'value' => $agreement->value_minor !== null ? Money::of($agreement->value_minor, (string) $agreement->currency)->toMajor() : null,
            'currency' => $agreement->currency,
        ];
    }

    /**
     * Sales document (21.9): order reference, warehouse, lines with codes, amounts and serials.
     *
     * @return array<string, mixed>
     */
    public function delivery(Delivery $delivery): array
    {
        $delivery->loadMissing(['order.customer', 'warehouse', 'lines.orderLine', 'lines.serialRows']);
        $order = $delivery->order;
        $m = fn (int $minor): string => Money::of($minor, $order->currency)->toMajor();
        $calculator = app(OfferCalculator::class);

        return [
            'number' => $delivery->number,
            'date' => $delivery->delivered_on?->toDateString(),
            'order' => ['crm_id' => $order->uuid, 'number' => $order->number, 'external_id' => $order->onec_guid, 'power_of_attorney' => $this->powerOfAttorney($order), 'source' => $order->source->value],
            'delivery_method' => $order->delivery_method->value,
            'pickup' => $order->isPickup() ? ['person' => $delivery->pickup_person, 'document' => $delivery->pickup_document] : null,
            'customer' => $this->customer($order->customer),
            'warehouse_id' => $delivery->warehouse?->external_id,
            'currency' => $order->currency,
            'lines' => $delivery->lines->filter(fn ($line): bool => bccomp((string) $line->quantity, '0', 3) > 0)->map(function ($line) use ($order, $m, $calculator): array {
                $orderLine = $line->orderLine;
                $amounts = $calculator->line(new CalculatorLine($orderLine->id, (string) $line->quantity, $orderLine->unit_price_minor, $orderLine->discount_bp, $orderLine->tax_rate_bp), $order->prices_include_vat);

                return [
                    'crm_order_line_id' => (string) $orderLine->id,
                    'item_id' => $orderLine->item_external_id !== null ? strtolower($orderLine->item_external_id) : null,
                    'accountant_code' => $orderLine->accountant_code,
                    'class_code' => $orderLine->class_code,
                    'package_code' => $orderLine->package_code,
                    'quantity' => (string) $line->quantity,
                    'unit_price' => $m($orderLine->unit_price_minor),
                    'net' => $m($amounts->net),
                    'vat' => $m($amounts->vat),
                    'total' => $m($amounts->gross),
                    'serials' => $line->is_serialized ? $line->serialRows->pluck('serial_number')->values()->all() : [],
                ];
            })->values()->all(),
        ];
    }

    /**
     * Goods receipt (21.9): quantities only; serials only for customer returns or when enabled.
     *
     * @return array<string, mixed>
     */
    public function goodsReceipt(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing(['warehouse', 'lines.item', 'lines.serialRows', 'customer', 'returnOfDelivery', 'supplierPartner']);
        $withSerials = $receipt->source_type === ReceiptSource::CustomerReturn || BusinessSettings::bool('serials.outbox_receipt_serials');

        return [
            'number' => $receipt->number,
            'date' => $receipt->received_on->toDateString(),
            'status' => $receipt->status->value,
            'source_type' => $receipt->source_type->value,
            // Spec 06 26: the 1C document this receipt completes (null for CRM-created kinds).
            'onec_document' => $receipt->isFromOneC() ? ['id' => $receipt->external_id, 'number' => $receipt->external_number, 'version' => $receipt->sync_version] : null,
            'supplier_tax_id' => $receipt->supplierPartner?->tax_id,
            'supplier_doc_ref' => $receipt->supplier_doc_ref,
            'warehouse_id' => $receipt->warehouse?->external_id,
            'customer' => $receipt->customer !== null ? $this->customer($receipt->customer) : null,
            'return_of_delivery' => $receipt->returnOfDelivery?->number,
            'lines' => $receipt->lines->map(function ($line) use ($withSerials): array {
                // Serialized lines: received and damaged are counted from the serial rows (Spec 06 26.4).
                $received = $line->is_serialized ? Quantity::toStorage((string) $line->serialRows->count()) : (string) $line->received_qty;
                $damaged = $line->is_serialized ? Quantity::toStorage((string) $line->serialRows->where('is_damaged', true)->count()) : (string) $line->damaged_qty;

                return [
                    'line_id' => $line->external_line_id,
                    'item_id' => $line->item?->external_id !== null ? strtolower($line->item->external_id) : null,
                    'accountant_code' => $line->item?->accountant_code,
                    'expected' => $line->expected_qty !== null ? (string) $line->expected_qty : null,
                    'received' => $received,
                    'damaged' => $damaged,
                    'discrepancy' => $line->expected_qty !== null ? bcsub($received, (string) $line->expected_qty, 3) : null,
                    'discrepancy_reason' => $line->discrepancy_reason?->value,
                    'note' => $line->note,
                    'serials' => $withSerials && $line->is_serialized ? $line->serialRows->pluck('serial_number')->values()->all() : null,
                    'damaged_serials' => $withSerials && $line->is_serialized ? $line->serialRows->where('is_damaged', true)->pluck('serial_number')->values()->all() : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Stock-off act (Spec 06 27.4): what left the books and why. Quantities and estimated value;
     * serials only when `serials.outbox_receipt_serials` is on (DEC-19).
     *
     * @return array<string, mixed>
     */
    public function stockOff(StockOffAct $act): array
    {
        $act->loadMissing(['warehouse', 'lines.item', 'lines.serials', 'goodsReceipt', 'supplierPartner']);
        $withSerials = BusinessSettings::bool('serials.outbox_receipt_serials');

        return [
            'number' => $act->number,
            'date' => $this->date($act->posted_at),
            'reason' => $act->reason->value,
            'note' => $act->note,
            'warehouse_id' => $act->warehouse?->external_id,
            'goods_receipt' => $act->goodsReceipt !== null ? [
                'crm_id' => $act->goodsReceipt->uuid,
                'number' => $act->goodsReceipt->number,
                'onec_document_id' => $act->goodsReceipt->external_id,
            ] : null,
            'supplier_tax_id' => $act->supplierPartner?->tax_id,
            'claim' => $act->claim_status !== 'none' ? ['status' => $act->claim_status, 'reference' => $act->claim_ref] : null,
            'totals' => collect($act->totals ?? [])->map(fn (int $minor, string $currency): string => Money::of($minor, $currency)->toMajor())->all(),
            'lines' => $act->lines->map(fn ($line): array => [
                'item_id' => $line->item?->external_id !== null ? strtolower($line->item->external_id) : null,
                'accountant_code' => $line->item?->accountant_code,
                'quantity' => (string) $line->quantity,
                'value' => $line->value_minor !== null ? Money::of($line->value_minor, (string) $line->currency)->toMajor() : null,
                'currency' => $line->currency,
                'serials' => $withSerials && $line->is_serialized ? $line->serials->pluck('serial_number')->values()->all() : null,
            ])->values()->all(),
        ];
    }

    /**
     * Stock adjustment (Spec 06 21.4): what changed and why. Serials are listed because the document is
     * about them (`found`: the new serial; `serial_correction`: old and new serial of the delivery note).
     *
     * @return array<string, mixed>
     */
    public function stockAdjustment(StockAdjustment $adjustment): array
    {
        $adjustment->loadMissing(['warehouse', 'delivery', 'lines.item', 'lines.oldSerial', 'lines.newSerial']);

        return [
            'number' => $adjustment->number,
            'date' => $this->date($adjustment->posted_at),
            'type' => $adjustment->type->value,
            'reason' => $adjustment->reason,
            'warehouse_id' => $adjustment->warehouse?->external_id,
            'delivery' => $adjustment->delivery !== null ? ['crm_id' => $adjustment->delivery->uuid, 'number' => $adjustment->delivery->number] : null,
            'lines' => $adjustment->lines->map(fn ($line): array => [
                'item_id' => $line->item?->external_id !== null ? strtolower($line->item->external_id) : null,
                'accountant_code' => $line->item?->accountant_code,
                'quantity' => (string) $line->quantity,
                'serial' => $line->serial_normalized,
                'old_serial' => $line->oldSerial?->serial_normalized,
                'old_serial_outcome' => $line->old_serial_outcome,
            ])->values()->all(),
        ];
    }

    /**
     * Billable repair (Spec 09 §10): customer, the unit (item GUID, serial), parts and labour.
     *
     * @return array<string, mixed>
     */
    public function serviceTicket(ServiceTicket $ticket): array
    {
        $ticket->loadMissing(['parts.item', 'asset.item', 'customer']);
        $m = fn (int $minor): string => $ticket->money($minor)->toMajor();

        return [
            'number' => $ticket->number,
            'date' => $this->date($ticket->closed_at),
            'customer' => $this->customer($ticket->customer),
            'currency' => (string) $ticket->currency,
            'asset' => [
                'item_id' => $ticket->asset->item?->external_id !== null ? strtolower($ticket->asset->item->external_id) : null,
                'name' => $ticket->asset->displayName(),
                'serial' => $ticket->asset->serial_raw,
            ],
            'under_warranty' => $ticket->under_warranty,
            'parts' => $ticket->parts->map(fn ($part): array => [
                'item_id' => $part->item?->external_id !== null ? strtolower($part->item->external_id) : null,
                'description' => $part->description,
                'quantity' => (string) $part->quantity,
                'unit_price' => $m($part->unit_price_minor),
                'total' => $m($ticket->money($part->unit_price_minor)->multiplyByQty((string) $part->quantity)->amount),
            ])->values()->all(),
            'labor' => $m((int) $ticket->labor_minor),
            'total' => $m($ticket->totalMinor()),
            'resolution' => $ticket->resolution,
        ];
    }

    /**
     * @return array{crm_id: string|null, external_id: string|null}
     */
    protected function customer(?Partner $customer): array
    {
        return ['crm_id' => $customer?->uuid, 'external_id' => $customer?->external_id !== null ? strtolower($customer->external_id) : null];
    }

    /**
     * Power of attorney checkbox of the order (Spec 06 section 28), available for 1C to pull.
     *
     * @return array{required: bool, received: bool, received_at: string|null, note: string|null, overridden: bool}
     */
    public function powerOfAttorney(Order $order): array
    {
        return [
            'required' => (bool) $order->poa_required,
            'received' => $order->poa_received_at !== null,
            'received_at' => $order->poa_received_at?->toIso8601String(),
            'note' => $order->poa_note,
            'overridden' => $order->poa_waived_at !== null,
        ];
    }

    public function date(?CarbonInterface $date): ?string
    {
        return $date?->copy()->setTimezone((string) config('orders.timezone'))->toDateString();
    }
}
