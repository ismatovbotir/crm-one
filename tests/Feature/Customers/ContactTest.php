<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\Show;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 01 AC-1.6 (contacts), AC-1.9, AC-1.10, BR-7.
 */
class ContactTest extends TestCase
{
    use RefreshDatabase;

    private User $rep;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rep = User::factory()->sales()->create();
        $this->customer = Customer::factory()->ownedBy($this->rep)->create();
        $this->actingAs($this->rep);
    }

    public function test_owner_adds_a_contact(): void
    {
        Livewire::test(Show::class, ['customer' => $this->customer])
            ->call('newContact')
            ->assertSet('is_primary', true)
            ->set('first_name', 'Aziz')
            ->set('email', 'AZIZ@alpha.uz')
            ->call('saveContact')
            ->assertHasNoErrors();

        $contact = $this->customer->contacts()->firstOrFail();
        $this->assertSame('aziz@alpha.uz', $contact->email);
        $this->assertTrue($contact->is_primary);
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'contact', 'subject_id' => $contact->id, 'event' => 'created']);
    }

    /** AC-1.9 */
    public function test_setting_a_new_primary_unsets_the_previous_one(): void
    {
        $a = Contact::factory()->for($this->customer)->primary()->create();
        $b = Contact::factory()->for($this->customer)->create();

        Livewire::test(Show::class, ['customer' => $this->customer])->call('makePrimary', $b->id);

        $this->assertFalse($a->fresh()->is_primary);
        $this->assertTrue($b->fresh()->is_primary);
        $this->assertSame(1, $this->customer->contacts()->where('is_primary', true)->count());
        $this->assertSame('primary_contact_changed', $this->customer->activities()->first()->event);
    }

    /** AC-1.10 */
    public function test_contact_needs_email_or_phone(): void
    {
        Livewire::test(Show::class, ['customer' => $this->customer])
            ->call('newContact')
            ->set('first_name', 'No Channel')
            ->call('saveContact')
            ->assertHasErrors(['email', 'phone']);

        $this->assertSame(0, $this->customer->contacts()->count());
    }

    /** AC-1.6 (contacts via the parent) */
    public function test_other_sales_rep_cannot_manage_contacts(): void
    {
        $contact = Contact::factory()->for($this->customer)->create();
        $this->actingAs(User::factory()->sales()->create());

        Livewire::test(Show::class, ['customer' => $this->customer])->call('newContact')->assertForbidden();
        Livewire::test(Show::class, ['customer' => $this->customer])->call('archiveContact', $contact->id)->assertForbidden();
        Livewire::test(Show::class, ['customer' => $this->customer])->call('makePrimary', $contact->id)->assertForbidden();

        $this->assertNotSoftDeleted($contact);
    }

    public function test_contacts_of_another_customer_cannot_be_targeted(): void
    {
        $foreign = Contact::factory()->create();

        Livewire::test(Show::class, ['customer' => $this->customer])->call('archiveContact', $foreign->id)->assertNotFound();

        $this->assertNotSoftDeleted($foreign);
    }

    /** BR-7: archiving the primary leaves none, and the UI prompts. */
    public function test_archiving_primary_contact_leaves_customer_without_primary(): void
    {
        $primary = Contact::factory()->for($this->customer)->primary()->create();
        Contact::factory()->for($this->customer)->create();

        Livewire::test(Show::class, ['customer' => $this->customer])
            ->call('archiveContact', $primary->id)
            ->set('tab', 'contacts')
            ->assertSee(__('customers.contacts.no_primary_prompt'));

        $this->assertSoftDeleted($primary);
        $this->assertSame(0, $this->customer->contacts()->where('is_primary', true)->count());
    }
}
