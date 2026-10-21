<?php

namespace App\Actions\Orders;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Models\Customer;
use App\Models\NumberSequence;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Unit;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\Offers\OfferToOrderPayload;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Support\BusinessSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only way an order is created (Spec 06 DEC-1, BR-OR.1/2/4): from the accepted offer's
 * OfferToOrderPayload, amounts and codes copied verbatim, one transaction with the offer row
 * locked. Idempotent: an existing order for the offer is returned; the unique `offer_id` index is
 * the final guard against a concurrent double call.
 */
class CreateOrderFromOffer
{
    public function __construct(protected OfferToOrderPayload $payload) {}

    public function handle(Offer $offer, ?User $user): Order
    {
        $existing = Order::query()->where('offer_id', $offer->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($offer, $user): Order {
                $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
                $existing = Order::query()->where('offer_id', $offer->id)->first();

                if ($existing !== null) {
                    return $existing;
                }

                try {
                    $payload = $this->payload->build($offer);
                } catch (OfferActionException) {
                    throw new OrderActionException('offer_not_accepted');
                }

                $order = $this->create($offer, $payload, $user);

                // Spec 06 section 31: no separate confirmation; the order starts right away.
                return app(StartOrder::class)->handle($order, $user);
            });
        } catch (UniqueConstraintViolationException) {
            return Order::query()->where('offer_id', $offer->id)->firstOrFail();
        }
    }

    /**
     * @param  array{offer: array<string, mixed>, lines: list<array<string, mixed>>, totals: array{net_minor: int, vat_minor: int, gross_minor: int, currency: string}}  $payload
     */
    protected function create(Offer $offer, array $payload, ?User $user): Order
    {
        $head = $payload['offer'];
        $year = (int) now((string) config('orders.timezone'))->year;
        // Spec 06 section 25: the sale type comes from the customer (default b2b); retail sells
        // against full payment before packaging and needs no agreement unless one is added.
        $customer = Customer::withTrashed()->find($head['customer_id']);
        $saleType = $customer?->sale_type ?? SaleType::default();
        $retail = $saleType === SaleType::Retail;

        $order = new Order([
            'number' => NumberSequence::format('OR', $year, NumberSequence::next('OR', $year)),
            'status' => OrderStatus::New,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'offer_id' => $offer->id,
            'offer_version' => $head['version'],
            'offer_accepted_hash' => $head['accepted_hash'],
            'lead_id' => $head['lead_id'],
            'customer_id' => $head['customer_id'],
            'contact_id' => $head['contact_id'],
            'owner_id' => $head['owner_id'],
            'currency' => $payload['totals']['currency'],
            'prices_include_vat' => (bool) $head['prices_include_vat'],
            'net_minor' => $payload['totals']['net_minor'],
            'vat_minor' => $payload['totals']['vat_minor'],
            'gross_minor' => $payload['totals']['gross_minor'],
            'payment_terms_days' => $retail ? 0 : $head['payment_terms_days'],
            'payment_terms_text' => $head['payment_terms_text'],
            'delivery_terms_text' => $head['delivery_terms_text'],
            'warranty_text' => $head['warranty_text'],
            'document_locale' => $offer->document_locale,
            'agreement_required' => ! $retail && BusinessSettings::bool('orders.agreement_required'),
            'sale_type' => $saleType,
            'prepayment_bp' => $retail ? 10000 : null,
        ]);
        // Spec 06 section 28: power of attorney required by default for B2B legal entities.
        $order->forceFill(['poa_required' => Order::poaRequiredByDefault($saleType, $customer)]);
        $order->save();

        $precisions = Unit::query()->whereIn('id', array_filter(array_column($payload['lines'], 'unit_id')))->pluck('precision', 'id');

        foreach ($payload['lines'] as $index => $line) {
            $order->lines()->create([
                'position' => $index + 1,
                'offer_line_id' => $line['offer_line_id'],
                'offer_option_group_id' => $line['offer_option_group_id'],
                'option_label' => $line['option_label'],
                'group_title' => $line['group_title'],
                'item_id' => $line['item_id'],
                'item_external_id' => $line['item_external_id'],
                'sku' => $line['sku'],
                'name' => $line['name'],
                'unit_id' => $line['unit_id'],
                'unit_code' => $line['unit_code'],
                'unit_name' => $line['unit_name'],
                'unit_precision' => (int) ($precisions[$line['unit_id']] ?? 3),
                'tax_rate_bp' => $line['tax_rate_bp'],
                'is_vat_exempt' => (bool) $line['is_vat_exempt'],
                'accountant_code' => $line['accountant_code'],
                'class_code' => $line['class_code'],
                'package_code' => $line['package_code'],
                'is_serialized' => (bool) $line['is_serialized'],
                'image_path' => $line['image_path'] ?? null,
                'quantity' => $line['quantity'],
                'unit_price_minor' => $line['unit_price_minor'],
                'discount_bp' => $line['discount_bp'],
                'line_net_minor' => $line['net_minor'],
                'line_vat_minor' => $line['vat_minor'],
                'line_gross_minor' => $line['gross_minor'],
                'cost_price_minor' => $line['cost_price_minor'],
                'lead_time_days' => $line['lead_time_days'],
            ]);
        }

        TransitionOrder::record($order, null, OrderStatus::New, $user, 'from_offer:'.$head['number']);

        return $order;
    }
}
