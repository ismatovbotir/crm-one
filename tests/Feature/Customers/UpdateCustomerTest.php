<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\Form;
use App\Livewire\Customers\Index;
use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 01 AC-1.6 .. 1.8, 1.11, 1.12, BR-13 and Spec 02 AC-I.42.
 */
class UpdateCustomerTest extends TestCase
{
    use RefreshDatabase;

    /** AC-1.6 */
    public function test_sales_rep_cannot_edit_someone_elses_customer(): void
    {
        $customer = Customer::factory()->create(['city' => 'Tashkent']);
        $this->actingAs(User::factory()->sales()->create());

        $this->get(route('customers.edit', $customer))->assertForbidden();
        Livewire::test(Form::class, ['customer' => $customer])->assertForbidden();

        $this->assertSame('Tashkent', $customer->fresh()->city);
    }

    /** AC-1.7 */
    public function test_sales_rep_cannot_change_owner_or_commercial_terms_of_own_customer(): void
    {
        $rep = User::factory()->sales()->create();
        $other = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($rep)->create();
        $this->actingAs($rep);

        Livewire::test(Form::class, ['customer' => $customer])->set('owner_id', $other->id)->call('save')->assertForbidden();
        Livewire::test(Form::class, ['customer' => $customer])->set('payment_terms_days', '90')->call('save')->assertForbidden();

        $customer->refresh();
        $this->assertSame($rep->id, $customer->owner_id);
        $this->assertNull($customer->payment_terms_days);
    }

    /** AC-1.8 */
    public function test_finance_sets_payment_terms_only(): void
    {
        $finance = User::factory()->finance()->create();
        $customer = Customer::factory()->create(['currency' => 'UZS', 'city' => 'Tashkent']);
        $this->actingAs($finance);

        Livewire::test(Form::class, ['customer' => $customer])->set('payment_terms_days', '15')->call('save')->assertHasNoErrors();

        $customer->refresh();
        $this->assertSame(15, $customer->payment_terms_days);
        $this->assertEquals(['payment_terms_days' => 15], $customer->activities()->first()->properties['new']);

        Livewire::test(Form::class, ['customer' => $customer])->set('city', 'Bukhara')->call('save')->assertForbidden();
        $this->assertSame('Tashkent', $customer->fresh()->city);
    }

    /** AC-1.12 */
    public function test_activity_records_only_the_changed_field(): void
    {
        $rep = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($rep)->create(['city' => 'Tashkent']);
        $this->actingAs($rep);

        Livewire::test(Form::class, ['customer' => $customer])->set('city', 'Samarkand')->call('save')->assertHasNoErrors();

        $log = $customer->activities()->first();
        $this->assertSame('updated', $log->event);
        $this->assertEquals(['old' => ['city' => 'Tashkent'], 'new' => ['city' => 'Samarkand']], $log->properties);
    }

    /** BR-13 */
    public function test_manager_changes_owner_and_it_is_logged(): void
    {
        $manager = User::factory()->manager()->create();
        $from = User::factory()->sales()->create();
        $to = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($from)->create();
        $this->actingAs($manager);

        Livewire::test(Form::class, ['customer' => $customer])->set('owner_id', $to->id)->call('save')->assertHasNoErrors();

        $this->assertSame($to->id, $customer->fresh()->owner_id);
        $this->assertSame('owner_changed', $customer->activities()->first()->event);
    }

    /** AC-I.42 */
    public function test_tax_id_is_locked_once_linked_to_1c(): void
    {
        $customer = Customer::factory()->fromOneC()->create(['tax_id' => '301234567']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Form::class, ['customer' => $customer])->set('tax_id', '309999999')->call('save')->assertHasErrors(['tax_id']);

        $this->assertSame('301234567', $customer->fresh()->tax_id);
    }

    /** AC-1.11 */
    public function test_manager_archives_and_only_admin_restores(): void
    {
        $customer = Customer::factory()->create(['name' => 'Archive Me']);
        $this->actingAs(User::factory()->manager()->create());

        Livewire::test(Show::class, ['customer' => $customer])->call('archive')->assertHasNoErrors();

        $this->assertSoftDeleted($customer);
        $this->assertSame('archived', $customer->activities()->first()->event);
        Livewire::test(Index::class)->assertDontSee('Archive Me')->set('archived', true)->assertSee('Archive Me');
        Livewire::test(Show::class, ['customer' => $customer->fresh()])->call('restore')->assertForbidden();
        $this->get(route('customers.edit', $customer))->assertNotFound();

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('customers.show', $customer))->assertOk()->assertSee(__('customers.archived_banner'));
        Livewire::test(Show::class, ['customer' => Customer::withTrashed()->find($customer->id)])->call('restore');

        $this->assertNotSoftDeleted($customer);
    }

    public function test_sales_rep_cannot_archive(): void
    {
        $rep = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($rep)->create();
        $this->actingAs($rep);

        Livewire::test(Show::class, ['customer' => $customer])->call('archive')->assertForbidden();

        $this->assertNotSoftDeleted($customer);
    }
}
