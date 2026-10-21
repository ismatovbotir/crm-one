<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\Show;
use App\Livewire\Leads\Form as LeadForm;
use App\Livewire\Orders\Create as OrderCreate;
use App\Livewire\Payments\ForOrders;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Quick "Create" actions on the customer card (user decision 2026-10-20): visibility by ability,
 * prefill through ?customer=ID, and no prefill of customers the user may not view.
 */
class CustomerCreateMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_shows_only_allowed_actions_with_prefill_links(): void
    {
        $rep = User::factory()->sales()->create();
        $customer = Customer::factory()->create(['owner_id' => $rep->id]);

        Livewire::actingAs($rep)->test(Show::class, ['customer' => $customer])
            ->assertSeeHtml('data-create-menu')
            ->assertSeeHtml('aria-haspopup="menu"')
            ->assertSeeHtml(e(route('offers.create', ['customer' => $customer->id])))
            ->assertSeeHtml(e(route('orders.create', ['customer' => $customer->id])))
            ->assertSeeHtml(e(route('leads.create', ['customer' => $customer->id])))
            ->assertDontSeeHtml('data-create="payment"');

        $accountant = User::factory()->accountant()->create();
        Livewire::actingAs($accountant)->test(Show::class, ['customer' => $customer])
            ->assertSeeHtml('data-create="payment"')
            ->assertDontSeeHtml('data-create="order"')
            ->assertDontSeeHtml('data-create="offer"');

        $otherRep = User::factory()->sales()->create();
        Livewire::actingAs($otherRep)->test(Show::class, ['customer' => $customer])->assertDontSeeHtml('data-create="order"');

        $customer->delete();
        Livewire::actingAs($rep)->test(Show::class, ['customer' => Customer::withTrashed()->find($customer->id)])->assertDontSeeHtml('data-create-menu');
    }

    public function test_forms_are_prefilled_from_the_query_string(): void
    {
        $rep = User::factory()->sales()->create();
        $customer = Customer::factory()->create(['owner_id' => $rep->id, 'name' => 'Fergana Textile']);

        Livewire::withQueryParams(['customer' => (string) $customer->id])->actingAs($rep)->test(OrderCreate::class)
            ->assertSet('customerId', (string) $customer->id)->assertSet('customerSearch', 'Fergana Textile');
        Livewire::withQueryParams(['customer' => (string) $customer->id])->actingAs($rep)->test(LeadForm::class)
            ->assertSet('customerId', $customer->id);
        Livewire::withQueryParams(['customer' => (string) $customer->id])->actingAs(User::factory()->accountant()->create())->test(ForOrders::class)
            ->assertSet('customerId', (string) $customer->id);
    }

    public function test_invalid_or_foreign_ids_are_ignored(): void
    {
        $outsider = User::factory()->staff()->create();
        $outsider->forceFill(['role' => 'sales'])->save();
        $limited = User::factory()->staff()->inDepartment('support')->create();
        $customer = Customer::factory()->create(['owner_id' => User::factory()->sales()->create()->id]);

        // A support specialist only sees the department's customers: the foreign prefill is dropped.
        Livewire::withQueryParams(['customer' => (string) $customer->id])->actingAs($limited)->test(LeadForm::class)->assertSet('customerId', null);
        Livewire::withQueryParams(['customer' => '999999'])->actingAs($outsider)->test(OrderCreate::class)->assertSet('customerId', '');
        Livewire::withQueryParams(['customer' => 'abc'])->actingAs($outsider)->test(LeadForm::class)->assertSet('customerId', null);
    }
}
