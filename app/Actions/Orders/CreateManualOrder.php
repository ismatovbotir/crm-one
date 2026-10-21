<?php

namespace App\Actions\Orders;

use App\Authorization\PermissionMap;
use App\Documents\DocumentFormatter;
use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Enums\DeliveryMethod;
use App\Enums\LegalType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Models\Agreement;
use App\Models\Customer;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Offers\Calculation\CalculatorLine;
use App\Services\Offers\DiscountRules;
use App\Services\Offers\OfferCalculator;
use App\Services\Offers\OfferValidator;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Pricing\Exceptions\ItemNotSellable;
use App\Services\Pricing\Exceptions\PriceNotFound;
use App\Services\Pricing\ItemPricing;
use App\Support\BusinessSettings;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Manual order without an offer (Spec 06 section 31, user decision 2026-10-20). Sales choose a
 * partner with the customer role (never a lead), the sale type (default: retail for private
 * persons, else the partner's), lines from the catalogue priced exactly like offer lines (single
 * sale price converted to the order VAT basis, cost basis snapshot), delivery or self pick-up,
 * address, one warehouse and a note. A discount above the user's limit or a line below cost is
 * refused here (there is no approval step on orders: make an offer for that). The partner's
 * active framework agreement is linked automatically unless "new agreement for this order" is
 * chosen. Snapshot immutable after creation; the order starts at once (StartOrder).
 */
class CreateManualOrder
{
    public function __construct(
        protected ItemPricing $prices,
        protected OfferCalculator $calculator,
        protected DiscountRules $discounts,
    ) {}

    /**
     * @param  array{customer_id: int, sale_type?: string|null, delivery_method: string, delivery_address?: string|null, warehouse_id: int, note?: string|null, own_agreement?: bool, lines: list<array{item_id: int, quantity: string, discount_bp?: int}>}  $data
     */
    public function handle(array $data, User $user): Order
    {
        $customer = Customer::query()->find($data['customer_id']);

        if ($customer === null || $customer->isDeletedInOneC()) {
            throw new OrderActionException('customer_unavailable');
        }

        if (! PermissionMap::allowsFor($user, 'orders.create', $customer->owner_id)) {
            throw new OrderActionException('create_forbidden');
        }

        $warehouse = Warehouse::query()->where('is_active', true)->find($data['warehouse_id']) ?? throw new OrderActionException('warehouse_required');
        $method = DeliveryMethod::tryFrom((string) $data['delivery_method']) ?? throw new OrderActionException('delivery_method_invalid');
        $address = trim((string) ($data['delivery_address'] ?? ''));

        if ($method === DeliveryMethod::Delivery && $address === '') {
            throw new OrderActionException('address_required');
        }

        $saleType = SaleType::tryFrom((string) ($data['sale_type'] ?? '')) ?? self::defaultSaleType($customer);
        $retail = $saleType->isRetail();
        $currency = strtoupper((string) ($customer->currency ?: config('crm.base_currency')));
        $includeVat = (bool) config('offers.prices_include_vat');
        $limit = $this->discounts->limitFor($user);
        $lines = $this->lines($data['lines'] ?? [], $currency, $includeVat, $limit);

        return DB::transaction(function () use ($data, $user, $customer, $warehouse, $method, $address, $saleType, $retail, $currency, $includeVat, $lines): Order {
            $year = (int) now((string) config('orders.timezone'))->year;
            $net = array_sum(array_column($lines, 'line_net_minor'));
            $vat = array_sum(array_column($lines, 'line_vat_minor'));

            $order = new Order([
                'number' => NumberSequence::format('OR', $year, NumberSequence::next('OR', $year)),
                'status' => OrderStatus::New,
                'payment_status' => OrderPaymentStatus::Unpaid,
                'customer_id' => $customer->id,
                'contact_id' => $customer->primaryContact?->id,
                'owner_id' => $customer->owner_id ?? $user->id,
                'currency' => $currency,
                'prices_include_vat' => $includeVat,
                'net_minor' => $net,
                'vat_minor' => $vat,
                'gross_minor' => $net + $vat,
                'payment_terms_days' => $retail ? 0 : $customer->payment_terms_days,
                'delivery_address' => $method === DeliveryMethod::Delivery ? mb_substr($address, 0, 2000) : null,
                'document_locale' => $customer->preferred_locale,
                'agreement_required' => ! $retail && BusinessSettings::bool('orders.agreement_required'),
                'sale_type' => $saleType,
                'prepayment_bp' => $retail ? 10000 : null,
                'source' => OrderSource::Manual,
                'delivery_method' => $method,
                'warehouse_id' => $warehouse->id,
                'note' => filled($data['note'] ?? null) ? mb_substr(trim((string) $data['note']), 0, 2000) : null,
            ]);
            $order->forceFill(['poa_required' => Order::poaRequiredByDefault($saleType, $customer)]);

            // Framework agreement (user decision): link the partner's active one unless the manager
            // asks for a new agreement for this order.
            if (! $retail && ! ($data['own_agreement'] ?? false)) {
                $order->framework_agreement_id = Agreement::query()->where('customer_id', $customer->id)->where('kind', AgreementKind::Framework)
                    ->where('status', AgreementStatus::Active)->orderByDesc('id')->value('id');
            }

            $order->save();

            foreach ($lines as $index => $line) {
                $order->lines()->create([...$line, 'position' => $index + 1, 'warehouse_id' => $warehouse->id]);
            }

            TransitionOrder::record($order, null, OrderStatus::New, $user, 'manual');
            $order->logActivity('created_manually', ['lines' => count($lines), 'delivery_method' => $method->value]);

            return app(StartOrder::class)->handle($order, $user);
        });
    }

    /** Default sale type: retail for private persons, else the partner's own setting. */
    public static function defaultSaleType(Customer $customer): SaleType
    {
        return $customer->legal_type === LegalType::Individual ? SaleType::Retail : ($customer->sale_type ?? SaleType::default());
    }

    /**
     * Prices, VAT, discounts and snapshots of the lines, exactly like offer lines.
     *
     * @param  list<array{item_id: int, quantity: string, discount_bp?: int}>  $input
     * @return list<array<string, mixed>>
     */
    public function lines(array $input, string $currency, bool $includeVat, int $limit): array
    {
        if ($input === []) {
            throw new OrderActionException('lines_required');
        }

        $lines = [];

        foreach ($input as $row) {
            $item = Item::query()->with(['unit', 'stocks'])->find($row['item_id'] ?? 0);

            if ($item === null || ! $item->isSellable()) {
                throw new OrderActionException('item_not_sellable', ['item' => $item?->name ?? '—']);
            }

            $quantity = Quantity::normalize((string) $row['quantity']);
            $error = OfferValidator::quantityError($quantity, $item->unit->precision, $item->is_serialized, $item->min_order_qty !== null ? (string) $item->min_order_qty : null, $item->order_step !== null ? (string) $item->order_step : null);

            if ($error !== null) {
                throw new OrderActionException('line_quantity_invalid', ['item' => $item->name]);
            }

            $discount = max(0, (int) ($row['discount_bp'] ?? 0));

            if ($discount > $limit) {
                throw new OrderActionException('discount_over_limit', ['item' => $item->name, 'limit' => rtrim(rtrim(bcdiv((string) $limit, '100', 2), '0'), '.')]);
            }

            try {
                $salePrice = $this->prices->salePrice($item, $currency);
            } catch (ItemNotSellable) {
                throw new OrderActionException('item_not_sellable', ['item' => $item->name]);
            } catch (PriceNotFound) {
                throw new OrderActionException('no_price', ['item' => $item->name, 'currency' => $currency]);
            }

            $taxRate = $item->is_vat_exempt ? 0 : $item->tax_rate_bp;
            $unitPrice = ItemPricing::toBasis($salePrice->amount, $item->prices_include_vat, $includeVat, $taxRate);
            $cost = $this->prices->costBasis($item, $currency);
            $amounts = $this->calculator->line(new CalculatorLine((string) count($lines), Quantity::toStorage($quantity), $unitPrice, $discount, $taxRate), $includeVat);

            // Below cost (net line vs net cost × quantity, as on offers) needs an approval, which only offers have.
            $costNet = $cost === null ? null : (int) DocumentFormatter::round(bcmul(Quantity::toStorage($quantity), (string) $cost, 6), 0);

            if ($costNet !== null && $amounts->net < $costNet) {
                throw new OrderActionException('below_cost', ['item' => $item->name]);
            }

            $lines[] = [
                'item_id' => $item->id,
                'item_external_id' => $item->external_id,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit_id' => $item->unit_id,
                'unit_code' => $item->unit->code,
                'unit_name' => $item->unit->name,
                'unit_precision' => (int) $item->unit->precision,
                'tax_rate_bp' => $taxRate,
                'is_vat_exempt' => (bool) $item->is_vat_exempt,
                'accountant_code' => $item->accountant_code,
                'class_code' => $item->class_code,
                'package_code' => $item->package_code,
                'is_serialized' => (bool) $item->is_serialized,
                'quantity' => Quantity::toStorage($quantity),
                'unit_price_minor' => $unitPrice,
                'discount_bp' => $discount,
                'line_net_minor' => $amounts->net,
                'line_vat_minor' => $amounts->vat,
                'line_gross_minor' => $amounts->gross,
                'cost_price_minor' => $cost,
            ];
        }

        return $lines;
    }
}
