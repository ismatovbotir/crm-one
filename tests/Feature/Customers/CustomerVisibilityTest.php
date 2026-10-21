<?php

namespace Tests\Feature\Customers;

use App\Enums\DepartmentRole;
use App\Livewire\Customers\Index;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Visibility scopes (own / department / all) applied to the customers list and pages.
 */
class CustomerVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_reps_see_all_customers_d7(): void
    {
        $rep = User::factory()->sales()->create();
        Customer::factory()->create(['name' => 'Someone Else Co']);

        $this->actingAs($rep);

        Livewire::test(Index::class)->assertSee('Someone Else Co');
    }

    public function test_department_scope_shows_only_colleagues_customers(): void
    {
        $viewer = User::factory()->staff()->inDepartment('support', DepartmentRole::Viewer)->create();
        $colleague = User::factory()->staff()->inDepartment('support')->create();
        $outsider = User::factory()->sales()->create();
        $visible = Customer::factory()->ownedBy($colleague)->create(['name' => 'Colleague Customer']);
        $hidden = Customer::factory()->ownedBy($outsider)->create(['name' => 'Outsider Customer']);

        $this->actingAs($viewer);

        Livewire::test(Index::class)
            ->assertSee('Colleague Customer')
            ->assertDontSee('Outsider Customer');

        $this->get(route('customers.show', $visible))->assertOk();
        $this->get(route('customers.show', $hidden))->assertForbidden();
        $this->assertSame([$visible->id], Customer::query()->visibleTo($viewer)->pluck('id')->all());
    }

    public function test_users_without_a_grant_see_nothing(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($staff);

        $this->get(route('customers.index'))->assertForbidden();
        $this->get(route('customers.show', $customer))->assertForbidden();
        $this->assertSame(0, Customer::query()->visibleTo($staff)->count());
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('customers.index'))->assertRedirect(route('login'));
    }

    public function test_search_filters_and_archive_toggle(): void
    {
        $rep = User::factory()->sales()->create();
        Customer::factory()->ownedBy($rep)->create(['name' => 'Alpha Trade', 'tax_id' => '301234567', 'phone' => '+998901112233']);
        Customer::factory()->create(['name' => 'Beta Foods', 'email' => 'hello@beta.uz']);
        Customer::factory()->archived()->create(['name' => 'Gamma Old']);

        $this->actingAs($rep);

        Livewire::test(Index::class)
            ->assertSee('Alpha Trade')->assertSee('Beta Foods')->assertDontSee('Gamma Old')
            ->set('search', '301 234')->assertSee('Alpha Trade')->assertDontSee('Beta Foods')
            ->set('search', 'hello@beta')->assertSee('Beta Foods')->assertDontSee('Alpha Trade')
            ->set('search', '90 111 22')->assertSee('Alpha Trade')
            ->set('search', '')
            ->set('owner', 'mine')->assertSee('Alpha Trade')->assertDontSee('Beta Foods')
            ->set('owner', '')
            ->set('archived', true)->assertSee('Gamma Old')->assertDontSee('Alpha Trade');
    }
}
