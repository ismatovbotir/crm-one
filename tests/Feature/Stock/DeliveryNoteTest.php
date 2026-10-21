<?php

namespace Tests\Feature\Stock;

use App\Actions\Orders\StartPackaging;
use App\Actions\Stock\AddReceiptSerials;
use App\Actions\Stock\ClaimSerials;
use App\Actions\Stock\ManageDeliveryNote;
use App\Actions\Stock\ManageGoodsReceipt;
use App\Actions\Stock\ManageSerials;
use App\Actions\Stock\Packaging;
use App\Actions\Stock\PostGoodsReceipt;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Enums\SerialStatus;
use App\Livewire\Stock\DeliveryShow;
use App\Livewire\Stock\SerialEntry;
use App\Models\DocumentLineSerial;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\ItemStock;
use App\Models\OnecOutboxEvent;
use App\Models\SerialEvent;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Spec 06 DEC-16 delivery note = sales document, BR-SN.6/7/8: AC-S4, AC-S6, AC-S7, AC-S9, AC-S16,
 * AC-7 (partial/complete deliveries), on-delivery schedule, outbox `delivery.posted`.
 */
class DeliveryNoteTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    private User $stock;

    private User $rep;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = User::factory()->stockManager()->create();
        $this->rep = User::factory()->sales()->create();
        $this->main = Warehouse::factory()->create(['name' => 'Main']);
    }

    /** AC-S4, AC-S6 */
    public function test_serials_are_checked_claimed_and_sold_on_posting(): void
    {
        $item = $this->serializedItem($this->main, '10', ['warranty_months' => 12]);
        $other = $this->serializedItem($this->main, '10', ['sku' => 'OTHER-1', 'name' => 'Other item']);
        $this->receiveSerials($this->stock, $this->main, $item, ['SNR1', 'SNR2', 'SNR3', 'SNR4']);
        $this->receiveSerials($this->stock, $this->main, $other, ['SNZ9']);
        $far = Warehouse::factory()->create(['name' => 'Far']);
        $this->receiveSerials($this->stock, $far, $item, ['SNF1']);

        $order = $this->orderInFulfilment($this->rep, $item, '3');
        $delivery = app(ManageDeliveryNote::class)->create($order, $this->rep, $this->main->id);
        $line = $delivery->lines()->sole();
        $this->assertMatchesRegularExpression('/^DN-\d{4}-\d{5}$/', $delivery->number);

        $results = app(ClaimSerials::class)->handle($line, ['SNZ9', 'NOPE1', 'SNF1', 'SNR1'], $this->stock);
        $this->assertSame(['belongs_to_other_item', 'not_found', 'wrong_warehouse', 'ok'], array_column($results, 'result'));
        $this->assertStringContainsString('Other item', $results[0]['message']);
        $this->assertStringContainsString('Far', $results[2]['message']);
        $this->assertSame([SerialStatus::Reserved, '1.000'], [ItemSerial::query()->where('serial_normalized', 'SNR1')->value('status'), (string) $line->fresh()->quantity]);

        // A second draft cannot take R1.
        $secondOrder = $this->orderInFulfilment($this->rep, $item, '1');
        $secondDelivery = app(ManageDeliveryNote::class)->create($secondOrder, $this->rep, $this->main->id);
        $taken = app(ClaimSerials::class)->handle($secondDelivery->lines()->sole(), ['SNR1'], $this->stock);
        $this->assertSame('reserved', $taken[0]['result']);
        $this->assertStringContainsString($delivery->number, $taken[0]['message']);

        try {
            app(Packaging::class)->complete($secondDelivery, $this->stock);
            $this->fail('A packing list without any registered serial cannot be completed.');
        } catch (StockActionException $exception) {
            $this->assertSame('delivery_empty', $exception->key);
        }

        app(ClaimSerials::class)->handle($line, ['SNR2'], $this->stock);

        $this->assertFalse($this->rep->can('post', $delivery), 'Sales draft but never post (SoD-S3).');
        Livewire::actingAs($this->stock)->test(DeliveryShow::class, ['delivery' => $delivery])->assertSee('data-incomplete', false);

        app(ClaimSerials::class)->handle($line->fresh(), ['SNR3'], $this->stock);
        $this->packAndPost($delivery, $this->stock);

        $sold = ItemSerial::query()->whereIn('serial_normalized', ['SNR1', 'SNR2', 'SNR3'])->get();
        $this->assertTrue($sold->every(fn (ItemSerial $s): bool => $s->status === SerialStatus::Sold && $s->customer_id === $order->customer_id && $s->warranty_until !== null));
        $this->assertSame(3, DocumentLineSerial::query()->where('line_type', 'delivery_line')->where('line_id', $line->id)->where('status', DocumentLineSerial::POSTED)->count());
        $this->assertSame([OrderStatus::Delivered, '3.000'], [$order->fresh()->status, (string) $order->lines()->sole()->delivered_qty]);
        $this->assertSame(ReservationStatus::Consumed, StockReservation::query()->where('order_line_id', $order->lines()->sole()->id)->value('status'));

        $event = OnecOutboxEvent::query()->where('event_type', 'delivery.posted')->sole();
        $this->assertSame(['SNR1', 'SNR2', 'SNR3'], $event->payload['lines'][0]['serials']);
        $this->assertSame('orders', $event->feed);

        $this->actingAs($this->stock)->get(route('delivery-notes.print', ['delivery' => $delivery, 'lang' => 'ru']))->assertOk()->assertSee('Накладная на отпуск товаров')->assertSee('SNR1, SNR2, SNR3');
        $this->actingAs($this->stock)->get(route('delivery-notes.warranty', ['delivery' => $delivery, 'lang' => 'uz']))->assertOk()->assertSee('Kafolat taloni')->assertSee('SNR2');
    }

    /** AC-S7 */
    public function test_cancelling_or_removing_releases_serials(): void
    {
        $item = $this->serializedItem($this->main);
        $this->receiveSerials($this->stock, $this->main, $item, ['SNC1', 'SNC2']);
        $order = $this->orderInFulfilment($this->rep, $item, '2');
        $delivery = app(ManageDeliveryNote::class)->create($order, $this->rep, $this->main->id);
        $line = $delivery->lines()->sole();
        app(ClaimSerials::class)->handle($line, ['SNC1', 'SNC2'], $this->stock);

        app(ManageSerials::class)->removeRow($line->serialRows()->firstWhere('serial_number', 'SNC1'), $this->stock);
        $this->assertSame([SerialStatus::Available, '1.000'], [ItemSerial::query()->where('serial_normalized', 'SNC1')->value('status'), (string) $line->fresh()->quantity]);

        app(ManageDeliveryNote::class)->cancel($delivery, $this->stock);
        $this->assertSame(SerialStatus::Available, ItemSerial::query()->where('serial_normalized', 'SNC2')->value('status'));
        $this->assertSame(2, SerialEvent::query()->where('event', 'released')->count());
    }

    public function test_partial_deliveries_and_on_delivery_schedule(): void
    {
        $plain = Item::factory()->priced('1000.00')->create(['tax_rate_bp' => 1200, 'is_serialized' => false]);
        ItemStock::factory()->create(['item_id' => $plain->id, 'warehouse_id' => $this->main->id, 'quantity' => '100']);
        $order = $this->orderInFulfilment($this->rep, $plain, '10');

        $first = app(ManageDeliveryNote::class)->create($order, $this->rep, $this->main->id);
        app(ManageDeliveryNote::class)->updateLine($first->lines()->sole(), '6');
        $this->packAndPost($first, $this->stock);
        $this->assertSame(OrderStatus::PartiallyDelivered, $order->fresh()->status);
        app(StartPackaging::class)->handle($order->fresh(), $this->stock);

        $second = app(ManageDeliveryNote::class)->create($order->fresh(), $this->rep, $this->main->id);
        $this->assertSame('4.000', (string) $second->lines()->sole()->quantity, 'Only the open quantity is proposed.');

        try {
            app(ManageDeliveryNote::class)->updateLine($second->lines()->sole(), '5');
            $this->fail('Delivering more than ordered is refused.');
        } catch (StockActionException $exception) {
            $this->assertSame('exceeds_open', $exception->key);
        }

        $this->packAndPost($second, $this->stock);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        $items = $order->fresh()->scheduleItems()->get();
        $this->assertSame([672_000, 448_000], $items->pluck('amount_minor')->all(), '6 and 4 units incl. VAT; Σ = gross.');
        $this->assertSame([$first->id, $second->id], $items->pluck('delivery_id')->all());
    }

    /** AC-S9 */
    public function test_customer_return_accepts_only_serials_sold_on_that_note(): void
    {
        $item = $this->serializedItem($this->main);
        $this->receiveSerials($this->stock, $this->main, $item, ['SNT1', 'SNT2']);
        $order = $this->orderInFulfilment($this->rep, $item, '1');
        $delivery = app(ManageDeliveryNote::class)->create($order, $this->rep, $this->main->id);
        app(ClaimSerials::class)->handle($delivery->lines()->sole(), ['SNT1'], $this->stock);
        $this->packAndPost($delivery, $this->stock);

        $receipt = app(ManageGoodsReceipt::class)->create(['source_type' => 'customer_return', 'warehouse_id' => $this->main->id, 'return_of_delivery_id' => $delivery->id], $this->stock);
        $line = $receipt->lines()->sole();
        app(ManageGoodsReceipt::class)->updateLine($line, ['received_qty' => '1']);

        $results = app(AddReceiptSerials::class)->handle($line, ['SNT2', 'SNT1'], $this->stock);
        $this->assertSame(['not_sold_on_delivery', 'ok'], array_column($results, 'result'));

        app(PostGoodsReceipt::class)->handle($receipt, $this->stock);
        $serial = ItemSerial::query()->where('serial_normalized', 'SNT1')->sole();
        $this->assertSame(SerialStatus::Returned, $serial->status);
        $this->assertSame('1.000', (string) $order->lines()->sole()->returned_qty);

        app(ManageSerials::class)->inspect($serial, $this->stock);
        $this->assertSame(SerialStatus::Available, $serial->fresh()->status);
    }

    public function test_serial_entry_component_and_sixty_serials_print_as_a_range(): void
    {
        $item = $this->serializedItem($this->main, '60');
        $serials = array_map(fn (int $n): string => sprintf('SN%06d', 100 + $n), range(1, 60));
        $this->receiveSerials($this->stock, $this->main, $item, $serials);
        $order = $this->orderInFulfilment($this->rep, $item, '60');
        $delivery = app(ManageDeliveryNote::class)->create($order, $this->rep, $this->main->id);
        $line = $delivery->lines()->sole();

        Livewire::actingAs($this->stock)->test(SerialEntry::class, ['lineType' => 'delivery_line', 'lineId' => $line->id])
            ->set('serial', ' sn000101 ')
            ->call('addOne')
            ->assertSee(__('stock.entry.counter', ['count' => 1, 'expected' => 60]))
            ->set('paste', implode("\n", array_slice($serials, 1)))
            ->call('addPaste')
            ->assertSee(__('stock.entry.counter', ['count' => 60, 'expected' => 60]));

        Livewire::actingAs($this->rep)->test(SerialEntry::class, ['lineType' => 'delivery_line', 'lineId' => $line->id])
            ->set('serial', 'SN000101')->call('addOne')->assertForbidden();

        $this->packAndPost($delivery, $this->stock);

        $this->actingAs($this->stock)->get(route('delivery-notes.print', ['delivery' => $delivery, 'lang' => 'en']))
            ->assertOk()
            ->assertSee(__('documents.serials.range', ['from' => 'SN000101', 'to' => 'SN000160', 'count' => 60], 'en'))
            ->assertSee('data-serial-annex', false);
    }
}
