<?php

namespace App\Services\Orders;

use App\Actions\Orders\CreateManualOrder;
use App\Documents\DocumentFormatter;
use App\Enums\OrderAmendmentType;
use App\Enums\OrderStatus;
use App\Models\DeliveryLine;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Offers\Calculation\CalculatorLine;
use App\Services\Offers\OfferCalculator;
use App\Services\Offers\OfferValidator;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Orders\Exceptions\OrderAmendmentException;
use App\Support\Quantity;

/**
 * What an order amendment may change (Spec 06 section 6.4). Every change is checked against the
 * current order when it is added and again when the amendment is applied:
 * - the order is started, not yet closed, and not waiting for a decision elsewhere;
 * - a quantity never goes below what was delivered, cancelled or sits on an open delivery note;
 * - a line is removed only when nothing of it was delivered or invoiced;
 * - serial-tracked quantities stay whole numbers, services follow the unit precision;
 * - a discount never takes a line below its cost basis.
 * One change per line and amendment. The line amounts always follow `quantity − cancelled_qty`.
 */
class OrderAmendmentRules
{
    /** Order states in which lines can still change. */
    public const AMENDABLE = [OrderStatus::Confirmed, OrderStatus::Packing, OrderStatus::Packed, OrderStatus::PartiallyDelivered];

    public function __construct(protected OfferCalculator $calculator) {}

    public function assertAmendable(Order $order): void
    {
        if (! in_array($order->status, self::AMENDABLE, true)) {
            throw new OrderAmendmentException('order_not_amendable', ['status' => $order->status->label()]);
        }
    }

    /**
     * Validates one change against the order as it is now and returns it in its stored shape
     * (with the current values and a label for display).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalize(Order $order, array $input): array
    {
        $this->assertAmendable($order);
        $type = OrderAmendmentType::tryFrom((string) ($input['type'] ?? '')) ?? throw new OrderAmendmentException('type_required');

        if ($type === OrderAmendmentType::AddLine) {
            return $this->addLine($order, $input);
        }

        $line = OrderLine::query()->where('order_id', $order->id)->find((int) ($input['order_line_id'] ?? 0)) ?? throw new OrderAmendmentException('line_not_found');
        $label = $line->position.'. '.$line->name;

        return match ($type) {
            OrderAmendmentType::QtyChange => $this->quantity($line, $label, (string) ($input['quantity'] ?? '')),
            OrderAmendmentType::PriceChange => $this->discount($order, $line, $label, (int) ($input['discount_bp'] ?? -1)),
            OrderAmendmentType::RemoveLine => $this->remove($order, $line, $label),
            OrderAmendmentType::CloseShort => $this->closeShort($order, $line, $label, (string) ($input['quantity'] ?? '')),
        };
    }

    /** Effective quantity of a line: ordered minus cancelled. */
    public function effectiveQuantity(OrderLine $line): string
    {
        return bcsub((string) $line->quantity, (string) $line->cancelled_qty, Quantity::SCALE);
    }

    /**
     * Quantity that cannot be taken away from a line: delivered, cancelled and on open delivery notes.
     */
    public function committedQuantity(OrderLine $line): string
    {
        $drafts = (string) (DeliveryLine::query()->where('order_line_id', $line->id)->whereHas('delivery', fn ($query) => $query->where('status', 'draft'))->sum('quantity') ?: '0');

        return bcadd(bcadd((string) $line->delivered_qty, (string) $line->cancelled_qty, Quantity::SCALE), $drafts, Quantity::SCALE);
    }

    /**
     * Line amounts on the effective quantity with the line's own price, discount and VAT.
     *
     * @return array{line_net_minor: int, line_vat_minor: int, line_gross_minor: int}
     */
    public function amounts(Order $order, OrderLine $line, ?string $quantity = null, ?int $discountBp = null): array
    {
        $amounts = $this->calculator->line(new CalculatorLine(
            (string) $line->id,
            $quantity ?? $this->effectiveQuantity($line),
            (int) $line->unit_price_minor,
            $discountBp ?? (int) $line->discount_bp,
            (int) $line->tax_rate_bp,
        ), (bool) $order->prices_include_vat);

        return ['line_net_minor' => $amounts->net, 'line_vat_minor' => $amounts->vat, 'line_gross_minor' => $amounts->gross];
    }

    /**
     * The discount a change needs approval for; 0 for changes that do not touch it.
     *
     * @param  array<string, mixed>  $change
     */
    public function discountOf(array $change): int
    {
        return in_array($change['type'] ?? null, [OrderAmendmentType::PriceChange->value, OrderAmendmentType::AddLine->value], true) ? (int) ($change['discount_bp'] ?? 0) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    protected function quantity(OrderLine $line, string $label, string $input): array
    {
        $quantity = Quantity::normalize($input);
        $item = $line->item;
        $error = OfferValidator::quantityError($quantity, (int) $line->unit_precision, (bool) $line->is_serialized, null, null);

        if ($error !== null) {
            throw new OrderAmendmentException('quantity_invalid', ['line' => $label]);
        }

        $quantity = Quantity::toStorage($quantity);

        if (bccomp($quantity, $this->committedQuantity($line), Quantity::SCALE) < 0) {
            throw new OrderAmendmentException('quantity_below_committed', ['line' => $label, 'committed' => Quantity::trimmed($this->committedQuantity($line))]);
        }

        if (bccomp($quantity, (string) $line->quantity, Quantity::SCALE) === 0) {
            throw new OrderAmendmentException('nothing_changed', ['line' => $label]);
        }

        if ($item !== null && ($item->min_order_qty !== null || $item->order_step !== null) && bccomp($quantity, (string) $line->quantity, Quantity::SCALE) > 0) {
            $stepError = OfferValidator::quantityError($quantity, (int) $line->unit_precision, (bool) $line->is_serialized, $item->min_order_qty !== null ? (string) $item->min_order_qty : null, $item->order_step !== null ? (string) $item->order_step : null);

            if ($stepError !== null) {
                throw new OrderAmendmentException('quantity_invalid', ['line' => $label]);
            }
        }

        return ['type' => OrderAmendmentType::QtyChange->value, 'order_line_id' => $line->id, 'label' => $label, 'old_quantity' => Quantity::trimmed((string) $line->quantity), 'quantity' => Quantity::trimmed($quantity)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function discount(Order $order, OrderLine $line, string $label, int $discountBp): array
    {
        if ($discountBp < 0 || $discountBp > 10000) {
            throw new OrderAmendmentException('discount_invalid', ['line' => $label]);
        }

        if ($discountBp === (int) $line->discount_bp) {
            throw new OrderAmendmentException('nothing_changed', ['line' => $label]);
        }

        $net = $this->amounts($order, $line, null, $discountBp)['line_net_minor'];

        if ($line->cost_price_minor !== null && $net < (int) DocumentFormatter::round(bcmul($this->effectiveQuantity($line), (string) $line->cost_price_minor, 6), 0)) {
            throw new OrderAmendmentException('below_cost', ['line' => $label]);
        }

        return ['type' => OrderAmendmentType::PriceChange->value, 'order_line_id' => $line->id, 'label' => $label, 'old_discount_bp' => (int) $line->discount_bp, 'discount_bp' => $discountBp];
    }

    /**
     * @return array<string, mixed>
     */
    protected function remove(Order $order, OrderLine $line, string $label): array
    {
        if (bccomp((string) $line->delivered_qty, '0', Quantity::SCALE) > 0 || bccomp((string) $line->invoiced_qty, '0', Quantity::SCALE) > 0 || bccomp($this->committedQuantity($line), (string) $line->cancelled_qty, Quantity::SCALE) > 0) {
            throw new OrderAmendmentException('line_in_use', ['line' => $label]);
        }

        if (bccomp($this->effectiveQuantity($line), '0', Quantity::SCALE) <= 0) {
            throw new OrderAmendmentException('nothing_changed', ['line' => $label]);
        }

        $this->assertOrderKeepsALine($order, [$line->id]);

        return ['type' => OrderAmendmentType::RemoveLine->value, 'order_line_id' => $line->id, 'label' => $label, 'quantity' => Quantity::trimmed($this->effectiveQuantity($line))];
    }

    /**
     * @return array<string, mixed>
     */
    protected function closeShort(Order $order, OrderLine $line, string $label, string $input): array
    {
        $quantity = Quantity::normalize($input);

        if (OfferValidator::quantityError($quantity, (int) $line->unit_precision, (bool) $line->is_serialized, null, null) !== null) {
            throw new OrderAmendmentException('quantity_invalid', ['line' => $label]);
        }

        $quantity = Quantity::toStorage($quantity);
        $open = bcsub((string) $line->quantity, $this->committedQuantity($line), Quantity::SCALE);

        if (bccomp($quantity, '0', Quantity::SCALE) <= 0 || bccomp($quantity, $open, Quantity::SCALE) > 0) {
            throw new OrderAmendmentException('close_short_over_open', ['line' => $label, 'open' => Quantity::trimmed($open)]);
        }

        if (bccomp((string) $line->delivered_qty, '0', Quantity::SCALE) === 0 && bccomp($quantity, $this->effectiveQuantity($line), Quantity::SCALE) === 0) {
            $this->assertOrderKeepsALine($order, [$line->id]);
        }

        return ['type' => OrderAmendmentType::CloseShort->value, 'order_line_id' => $line->id, 'label' => $label, 'quantity' => Quantity::trimmed($quantity)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function addLine(Order $order, array $input): array
    {
        $item = Item::query()->find((int) ($input['item_id'] ?? 0)) ?? throw new OrderAmendmentException('item_required');

        $discount = max(0, (int) ($input['discount_bp'] ?? 0));

        try {
            $row = app(CreateManualOrder::class)->lines([['item_id' => $item->id, 'quantity' => (string) ($input['quantity'] ?? ''), 'discount_bp' => $discount]], $order->currency, (bool) $order->prices_include_vat, 10000)[0];
        } catch (OrderActionException $exception) {
            throw new OrderAmendmentException('line_refused', ['detail' => $exception->getMessage()]);
        }

        return [
            'type' => OrderAmendmentType::AddLine->value, 'item_id' => $item->id, 'label' => $item->sku.' '.$item->name,
            'quantity' => Quantity::trimmed((string) $row['quantity']), 'discount_bp' => $discount,
        ];
    }

    /**
     * The order must keep a deliverable line (or something delivered); emptying it means cancelling the order.
     *
     * @param  list<int>  $removing
     */
    protected function assertOrderKeepsALine(Order $order, array $removing): void
    {
        $remaining = OrderLine::query()->where('order_id', $order->id)->whereNotIn('id', $removing)->get()
            ->contains(fn (OrderLine $line): bool => bccomp($this->effectiveQuantity($line), '0', Quantity::SCALE) > 0);

        if (! $remaining) {
            throw new OrderAmendmentException('would_empty_order');
        }
    }
}
