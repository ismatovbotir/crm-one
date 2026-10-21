<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\ChangeSaleType;
use App\Actions\Orders\RecordPowerOfAttorney;
use App\Enums\SaleType;
use App\Livewire\Orders\PowerOfAttorney;
use App\Models\Customer;
use App\Models\User;
use App\Services\OneC\Outbox\OutboxPayloads;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Orders\OrderFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 section 28: "power of attorney received" checkbox, its default, who may tick / untick /
 * override, the flow step before the tax invoice and the 1C payload.
 */
class PowerOfAttorneyTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    public function test_default_follows_legal_type_and_sale_type(): void
    {
        $rep = User::factory()->sales()->create();
        $this->assertTrue($this->simpleOrder($rep)->poa_required, 'Legal entities on B2B need it.');

        $person = Customer::factory()->create(['owner_id' => $rep->id, 'legal_type' => 'individual', 'tax_id' => null]);
        $this->assertFalse($this->simpleOrder($rep, '1', '100', $person)->poa_required, 'Private persons never.');

        $order = $this->simpleOrder($rep);
        app(ChangeSaleType::class)->handle($order, SaleType::Retail, $rep);
        $this->assertFalse($order->fresh()->poa_required, 'Retail never.');
    }

    public function test_tick_untick_override_and_requirement_rights(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep);
        $record = app(RecordPowerOfAttorney::class);

        $this->assertFalse(User::factory()->sales()->create()->can('tickPoa', $order), 'Another sales rep cannot tick.');
        $this->assertTrue($rep->can('tickPoa', $order));
        $record->tick($order, $rep, 'No. 7 of 19.10.2026');
        $this->assertTrue($order->fresh()->poaSatisfied());
        $this->assertSame($rep->id, $order->fresh()->poa_received_by);
        $this->assertFalse($rep->can('untickPoa', $order->fresh()), 'The sales rep cannot untick.');

        $accountant = User::factory()->accountant()->create();
        try {
            $record->untick($order->fresh(), $accountant, '');
            $this->fail('A reason is required.');
        } catch (OrderActionException $exception) {
            $this->assertSame('poa_reason_required', $exception->key);
        }

        $record->untick($order->fresh(), $accountant, 'Wrong document');
        $this->assertFalse($order->fresh()->poaSatisfied());
        $this->assertTrue($order->fresh()->activities()->where('event', 'poa_unticked')->exists());

        $this->assertFalse($accountant->can('overridePoa', $order->fresh()));
        $record->override($order->fresh(), User::factory()->manager()->create(), 'Customer collects in person, POA follows');
        $this->assertTrue($order->fresh()->poaSatisfied());

        $finance = User::factory()->finance()->create();
        $record->setRequired($order->fresh(), false, $finance, 'Own courier');
        $this->assertFalse($order->fresh()->poa_required);
    }

    public function test_flow_step_component_and_payload(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep);
        $keys = array_column(app(OrderFlow::class)->steps($order), 'key');
        $this->assertSame(array_search('invoice', $keys, true) - 1, array_search('power_of_attorney', $keys, true), 'Power of attorney comes right before the invoice.');

        Livewire::actingAs($rep)->test(PowerOfAttorney::class, ['orderId' => $order->id])
            ->assertSee(__('orders.poa.checkbox'))
            ->set('note', 'No. 3, B. Aliev')
            ->call('tick');
        $this->assertSame('No. 3, B. Aliev', $order->fresh()->poa_note);

        $payload = app(OutboxPayloads::class)->order($order->fresh());
        $this->assertSame(['required' => true, 'received' => true, 'note' => 'No. 3, B. Aliev', 'overridden' => false], array_intersect_key($payload['power_of_attorney'], array_flip(['required', 'received', 'note', 'overridden'])));
        $this->assertSame('done', collect(app(OrderFlow::class)->steps($order->fresh()))->firstWhere('key', 'power_of_attorney')['state']);
    }
}
