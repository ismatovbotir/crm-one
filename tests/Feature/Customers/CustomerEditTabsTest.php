<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\Form;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The customer edit page is split into Company, Address and Commercial tabs; create keeps the
 * single stacked form. A failed save lands on the tab that holds the error.
 */
class CustomerEditTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_shows_one_tab_at_a_time(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $customer = Customer::factory()->create();

        Livewire::test(Form::class, ['customer' => $customer])
            ->assertSeeHtml('data-tab-panel="company"')
            ->assertSeeHtml('id="legal_name"')
            ->assertDontSeeHtml('id="currency"')
            ->assertDontSeeHtml('id="country"')
            ->set('tab', 'address')
            ->assertSeeHtml('data-tab-panel="address"')
            ->assertSeeHtml('data-iso-code')
            ->assertDontSeeHtml('id="legal_name"')
            ->set('tab', 'commercial')
            ->assertSeeHtml('id="currency"')
            ->assertSeeHtml('id="payment_terms_days"');
    }

    public function test_an_unknown_tab_falls_back_to_company(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $customer = Customer::factory()->create();

        Livewire::withQueryParams(['tab' => 'bogus'])->test(Form::class, ['customer' => $customer])
            ->assertSet('tab', 'company')
            ->set('tab', 'nonsense')
            ->assertSet('tab', 'company');
    }

    public function test_values_typed_on_one_tab_survive_switching_and_are_saved(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $customer = Customer::factory()->create(['city' => 'Tashkent']);

        Livewire::test(Form::class, ['customer' => $customer])
            ->set('name', 'Renamed Co')
            ->set('tab', 'address')
            ->set('city', 'Samarkand')
            ->set('tab', 'commercial')
            ->call('save')
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertSame('Renamed Co', $customer->name);
        $this->assertSame('Samarkand', $customer->city);
    }

    public function test_a_failed_save_jumps_to_the_first_tab_with_an_error(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $customer = Customer::factory()->create();

        Livewire::test(Form::class, ['customer' => $customer])
            ->set('currency', 'ZZZ')
            ->call('save')
            ->assertHasErrors(['currency'])
            ->assertSet('tab', 'commercial')
            ->set('tab', 'company')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name', 'currency'])
            ->assertSet('tab', 'company')
            ->assertSeeHtml('data-tab-marker');
    }
}
