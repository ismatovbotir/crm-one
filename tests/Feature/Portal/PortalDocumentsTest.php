<?php

namespace Tests\Feature\Portal;

use App\Actions\Agreements\GenerateAgreement;
use App\Actions\Agreements\SendAgreement;
use App\Actions\Customers\ManagePortalAccess;
use App\Actions\Orders\ConfirmOrder;
use App\Enums\AgreementChannel;
use App\Enums\AgreementKind;
use App\Livewire\Customers\PortalAccess;
use App\Models\Order;
use App\Models\PortalMembership;
use App\Models\User;
use App\Services\Agreements\Exceptions\AgreementActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 03 4.7/4.8 + Spec 06 AC-14: staff give portal access from the customer card; agreements go
 * out through the portal channel only for portal-enabled customers; finance roles see and print
 * them in the portal, other roles and other companies do not.
 */
class PortalDocumentsTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private User $rep;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpCompany();
        $this->seedAgreementTemplates();
        $this->rep = User::factory()->sales()->create();
        $this->order = $this->simpleOrder($this->rep, '2', '10', $this->agreementReadyCustomer($this->rep));
        app(ConfirmOrder::class)->handle($this->order, $this->rep);
        $this->order->refresh();
    }

    public function test_sales_owner_gives_portal_access_from_the_customer_card(): void
    {
        $customer = $this->order->customer;

        Livewire::actingAs($this->rep)->test(PortalAccess::class, ['customerId' => $customer->id])
            ->set('enabled', true)->set('threshold', '5000000')->call('configure')
            ->set('form.email', 'Fin@Buyer.uz')->set('form.name', 'Finance Person')->set('form.roles', ['finance'])->call('grant')
            ->assertHasNoErrors()->assertSee('fin@buyer.uz');

        $customer->refresh();
        $this->assertTrue($customer->portal_enabled);
        $this->assertSame(500000000, $customer->portal_approval_threshold_minor);
        $membership = PortalMembership::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(['finance'], $membership->roles);
        $this->assertNull($membership->user->password);

        $stranger = User::factory()->sales()->create();
        Livewire::actingAs($stranger)->test(PortalAccess::class, ['customerId' => $customer->id])->assertForbidden();
    }

    public function test_agreement_portal_channel_requires_the_portal_and_is_visible_to_finance_roles(): void
    {
        $accountant = User::factory()->accountant()->create();
        $agreement = app(GenerateAgreement::class)->handle(AgreementKind::Order, $this->order->customer, $this->order, $accountant);

        try {
            app(SendAgreement::class)->handle($agreement, $accountant, [AgreementChannel::Portal]);
            $this->fail('The portal channel must be refused while the portal is off.');
        } catch (AgreementActionException $exception) {
            $this->assertSame('portal_not_enabled', $exception->key);
        }

        $access = app(ManagePortalAccess::class);
        $access->configure($this->order->customer, true, null);
        $finance = $access->grant($this->order->customer->fresh(), 'fin@buyer.uz', 'Finance', ['finance'], null, null, $this->rep);
        $buyer = $access->grant($this->order->customer->fresh(), 'buy@buyer.uz', 'Buyer', ['buyer'], null, null, $this->rep);
        app(SendAgreement::class)->handle($agreement->fresh(), $accountant, [AgreementChannel::Portal]);
        $agreement->refresh();

        $this->actingAs($finance->user->fresh(), 'portal');
        $this->get(route('portal.agreements.index'))->assertOk()->assertSee($agreement->number);
        $this->get(route('portal.agreements.print', $agreement))->assertOk()->assertSee($agreement->number);

        $this->actingAs($buyer->user->fresh(), 'portal');
        $this->get(route('portal.agreements.index'))->assertForbidden();
        $this->get(route('portal.agreements.print', $agreement))->assertNotFound();
        $this->get(route('portal.orders.show', $this->order))->assertOk()->assertSee($this->order->number);
    }
}
