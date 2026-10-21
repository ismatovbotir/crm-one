<?php

namespace Tests\Concerns;

use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\SendOffer;
use App\Actions\Orders\ConfirmOrder;
use App\Actions\Orders\StartPackaging;
use App\Actions\Orders\WaiveAgreement;
use App\Actions\Stock\AddReceiptSerials;
use App\Actions\Stock\Packaging;
use App\Actions\Stock\PostDeliveryNote;
use App\Actions\Stock\PostGoodsReceipt;
use App\Actions\Stock\TransitionGoodsReceipt;
use App\Enums\GoodsReceiptStatus;
use App\Enums\SelectionChannel;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OneC\Inbound\GoodsReceiptImporter;
use Illuminate\Support\Str;

/**
 * Stock fixtures through the real Actions: serialized items, posted receipts with serials, orders
 * in fulfilment ready for a delivery note.
 */
trait BuildsStock
{
    use BuildsOrders;

    protected function serializedItem(Warehouse $warehouse, string $stock = '10', array $attributes = []): Item
    {
        $item = Item::factory()->priced('1000.00')->create(['tax_rate_bp' => 1200, 'is_serialized' => true, 'name' => 'Router X', ...$attributes]);
        ItemStock::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => $stock, 'reserved' => '0']);

        return $item;
    }

    /**
     * Spec 06 26: a supplier receipt pushed by 1C, started, serials entered, checked and posted.
     *
     * @param  list<string>  $serials
     */
    protected function receiveSerials(User $stockManager, Warehouse $warehouse, Item $item, array $serials): GoodsReceipt
    {
        $receipt = $this->receiptFromOneC($warehouse, [[$item, (string) count($serials)]]);
        $transition = app(TransitionGoodsReceipt::class);
        $transition->handle($receipt, GoodsReceiptStatus::InProgress, $stockManager);
        app(AddReceiptSerials::class)->handle($receipt->lines()->firstOrFail(), $serials, $stockManager);
        $transition->handle($receipt->fresh(), GoodsReceiptStatus::Checked, $stockManager);

        return app(PostGoodsReceipt::class)->handle($receipt->fresh(), $stockManager);
    }

    /**
     * A goods receipt as 1C pushes it (POST /goods-receipts), through the real importer.
     *
     * @param  list<array{0: Item, 1: string}>  $lines
     * @param  array<string, mixed>  $overrides
     */
    protected function receiptFromOneC(Warehouse $warehouse, array $lines, array $overrides = []): GoodsReceipt
    {
        $record = $this->oneCReceiptRecord($warehouse, $lines, $overrides);
        $result = app(GoodsReceiptImporter::class)->upsert($record, 0);
        $this->assertContains($result['result'], ['created', 'updated'], json_encode($result) ?: '');

        return GoodsReceipt::query()->where('uuid', $result['crm_id'])->firstOrFail();
    }

    /**
     * @param  list<array{0: Item, 1: string}>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function oneCReceiptRecord(Warehouse $warehouse, array $lines, array $overrides = []): array
    {
        if ($warehouse->external_id === null) {
            $warehouse->forceFill(['external_id' => (string) Str::uuid()])->save();
        }

        $taxId = '30'.random_int(1000000, 9999999);
        Customer::factory()->create(['tax_id' => $taxId, 'name' => 'Supplier LLC']);

        return [
            'id' => (string) Str::uuid(),
            'number' => 'ПТ-'.random_int(1000, 9999),
            'date' => now()->toDateString(),
            'warehouse_id' => $warehouse->external_id,
            'supplier' => ['tax_id' => $taxId, 'name' => 'Supplier LLC'],
            'lines' => array_map(function (array $line): array {
                [$item, $qty] = $line;

                if ($item->external_id === null) {
                    $item->forceFill(['external_id' => (string) Str::uuid()])->save();
                }

                return ['line_id' => (string) Str::uuid(), 'item' => ['id' => $item->external_id], 'expected_qty' => $qty];
            }, $lines),
            ...$overrides,
        ];
    }

    /** Accepted, confirmed, agreement waived and fulfilment started. */
    protected function orderInFulfilment(User $rep, Item $item, string $quantity): Order
    {
        $offer = $this->draftOffer($rep);
        $this->addLine($offer, $item, $quantity);
        app(SendOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email);
        app(AcceptOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email, 'ok');
        $order = Order::query()->where('offer_id', $offer->id)->firstOrFail();
        app(ConfirmOrder::class)->handle($order, $rep, true);
        app(WaiveAgreement::class)->handle($order, User::factory()->manager()->create(), 'test');
        app(StartPackaging::class)->handle($order->fresh(), $rep);

        return $order->fresh();
    }

    /** Spec 06 section 25: complete packaging of the packing list, then post the delivery. */
    protected function packAndPost(Delivery $delivery, User $stockManager): Delivery
    {
        app(Packaging::class)->complete($delivery, $stockManager);

        return app(PostDeliveryNote::class)->handle($delivery->fresh(), $stockManager);
    }
}
